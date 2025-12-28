// Simple uploader + chunked import controller
(function () {
	// FNV-1a 64-bit hash - optimized with pre-computed BigInt table
	const FNV_TABLE = Array.from({ length: 256 }, (_, i) => BigInt(i));

	function fnv1a64(buf) {
		let hash = 0xcbf29ce484222325n;
		const prime = 0x100000001b3n;

		for (let i = 0; i < buf.length; i++) {
			hash ^= FNV_TABLE[buf[i]];
			hash = (hash * prime) & 0xffffffffffffffffn;
		}

		return hash.toString(16).padStart(16, "0");
	}

	async function uploadChunkWithRetry(jobId, offset, chunk, maxRetries = 3) {
		const buf = new Uint8Array(await chunk.arrayBuffer());
		const checksum = fnv1a64(buf);

		for (let attempt = 1; attempt <= maxRetries; attempt++) {
			try {
				const xhr = await new Promise((resolve, reject) => {
					const xhr = new XMLHttpRequest();
					let _url = `dbimport.php?action=upload_chunk&job_id=${encodeURIComponent(
						jobId
					)}&offset=${offset}`;
					_url = appendServerToUrl(_url);
					xhr.open("POST", _url);
					xhr.setRequestHeader("X-Checksum", checksum);
					xhr.setRequestHeader(
						"Content-Type",
						"application/octet-stream"
					);

					xhr.onload = () => resolve(xhr);
					xhr.onerror = () => reject(new Error("Network error"));
					xhr.ontimeout = () => reject(new Error("Timeout"));

					xhr.send(buf);
				});

				if (xhr.status >= 200 && xhr.status < 300) {
					const res = JSON.parse(xhr.responseText);
					if (res.status === "OK") {
						return res.uploaded_bytes;
					}
					if (res.status === "BAD_CHECKSUM") {
						console.warn(
							`Chunk @${offset} checksum mismatch (attempt ${attempt})`
						);
						continue;
					}
					throw new Error(res.error || "Upload failed");
				}
				throw new Error(`HTTP ${xhr.status}`);
			} catch (e) {
				if (attempt === maxRetries) {
					throw new Error(
						`Chunk failed after ${maxRetries} retries: ${e.message}`
					);
				}
				console.warn(
					`Retry ${attempt}/${maxRetries} for chunk @${offset}`
				);
				await new Promise((r) => setTimeout(r, 500 * attempt));
			}
		}
	}

	function el(id) {
		return document.getElementById(id);
	}

	// Read server context emitted by server-side renderer.
	const SERVER_ID = (function () {
		const inp = document.getElementById("import_server");
		if (inp && inp.value) return inp.value;
		const ui = document.getElementById("importUI");
		if (ui && ui.dataset && ui.dataset.server) return ui.dataset.server;
		return "";
	})();

	function appendServerToUrl(url) {
		if (!SERVER_ID) return url;
		return (
			url +
			(url.indexOf("?") === -1 ? "?" : "&") +
			"server=" +
			encodeURIComponent(SERVER_ID)
		);
	}

	function appendServerToParams(params) {
		if (!SERVER_ID) return params;
		try {
			if (
				params instanceof URLSearchParams ||
				params instanceof FormData
			) {
				params.append("server", SERVER_ID);
				return params;
			}
		} catch (e) {
			// ignore
		}
		if (typeof params === "string") {
			return params + "&server=" + encodeURIComponent(SERVER_ID);
		}
		return params;
	}

	function formatBytes(bytes) {
		if (bytes === 0) return "0 B";
		const k = 1024;
		const sizes = ["B", "KB", "MB", "GB", "TB"];
		const i = Math.floor(Math.log(bytes) / Math.log(k));
		return (
			Math.round((bytes / Math.pow(k, i)) * 100) / 100 + " " + sizes[i]
		);
	}

	function getServerCaps(fileInput) {
		const ds = fileInput && fileInput.dataset ? fileInput.dataset : {};
		return {
			gzip: ds.capGzip === "1",
			zip: ds.capZip === "1",
			bzip2: ds.capBzip2 === "1",
		};
	}

	function detectZipSignature(bytes) {
		// ZIP signatures:
		// 50 4B 03 04 local file header
		// 50 4B 05 06 end of central directory (empty archive)
		// 50 4B 07 08 spanned archive
		return (
			bytes.length >= 4 &&
			bytes[0] === 0x50 &&
			bytes[1] === 0x4b &&
			((bytes[2] === 0x03 && bytes[3] === 0x04) ||
				(bytes[2] === 0x05 && bytes[3] === 0x06) ||
				(bytes[2] === 0x07 && bytes[3] === 0x08))
		);
	}

	async function sniffMagicType(file) {
		// Returns: 'gzip' | 'bzip2' | 'zip' | 'plain' | 'unknown'
		try {
			const blob = file.slice(0, 8);
			let buf;
			if (blob.arrayBuffer) {
				buf = await blob.arrayBuffer();
			} else {
				buf = await new Promise((resolve, reject) => {
					const reader = new FileReader();
					reader.onload = () => resolve(reader.result);
					reader.onerror = () => reject(reader.error);
					reader.readAsArrayBuffer(blob);
				});
			}
			const bytes = new Uint8Array(buf || []);
			if (bytes.length >= 2 && bytes[0] === 0x1f && bytes[1] === 0x8b)
				return "gzip";
			if (
				bytes.length >= 3 &&
				bytes[0] === 0x42 &&
				bytes[1] === 0x5a &&
				bytes[2] === 0x68
			)
				return "bzip2";
			if (detectZipSignature(bytes)) return "zip";
			return bytes.length > 0 ? "plain" : "unknown";
		} catch (e) {
			return "unknown";
		}
	}

	async function startUpload() {
		const fileInput = el("file");
		if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
			alert("No file selected");
			return;
		}
		const file = fileInput.files[0];

		const maxAttr = fileInput.dataset
			? fileInput.dataset.importMaxSize
			: null;
		if (maxAttr) {
			const maxSize = parseInt(maxAttr, 10) || 0;
			if (maxSize > 0 && file.size > maxSize) {
				alert("Selected file exceeds maximum allowed upload size.");
				return;
			}
		}

		// Sniff magic bytes client-side to prevent uploading unsupported compressed files
		const caps = getServerCaps(fileInput);
		const detectedType = await sniffMagicType(file);
		if (detectedType === "zip" && !caps.zip) {
			alert(
				"This file appears to be a ZIP archive, but ZIP support is not available on the server (PHP ext-zip / ZipArchive)."
			);
			return;
		}
		if (detectedType === "gzip" && !caps.gzip) {
			alert(
				"This file appears to be gzip-compressed, but gzip support is not available on the server (PHP ext-zlib)."
			);
			return;
		}
		if (detectedType === "bzip2" && !caps.bzip2) {
			alert(
				"This file appears to be bzip2-compressed, but bzip2 support is not available on the server (PHP ext-bz2)."
			);
			return;
		}

		const scope = el("import_scope")
			? el("import_scope").value
			: "database";
		const scope_ident = el("import_scope_ident")
			? el("import_scope_ident").value
			: "";

		// Show upload UI
		const importUI = el("importUI");
		const uploadPhase = el("uploadPhase");
		const importPhase = el("importPhase");
		if (importUI) importUI.style.display = "block";
		if (uploadPhase) uploadPhase.style.display = "block";
		if (importPhase) importPhase.style.display = "none";

		const uploadStatus = el("uploadStatus");
		if (uploadStatus) {
			uploadStatus.textContent = `Initializing upload for ${
				file.name
			} (${formatBytes(file.size)})...`;
		}

		try {
			// 1. Initialize upload and get job_id + chunk_size
			const formData = new URLSearchParams();
			formData.append("filename", file.name);
			formData.append("filesize", file.size);
			formData.append("scope", scope);
			formData.append("scope_ident", scope_ident);

			// Collect options
			const opts = [
				"opt_roles",
				"opt_tablespaces",
				"opt_databases",
				"opt_schema_create",
				"opt_data",
				"opt_truncate",
				"opt_ownership",
				"opt_rights",
				"opt_defer_self",
				"opt_allow_drops",
			];
			opts.forEach((opt) => {
				const chk = document.getElementById(opt);
				if (chk && chk.checked) {
					formData.append(opt, "1");
				}
			});
			const errorModeRadios =
				document.getElementsByName("opt_error_mode");
			for (let radio of errorModeRadios) {
				if (radio.checked) {
					formData.append("opt_error_mode", radio.value);
					break;
				}
			}

			appendServerToParams(formData);
			const initRes = await fetch(
				appendServerToUrl("dbimport.php?action=init_upload"),
				{
					method: "POST",
					body: formData,
				}
			);

			if (!initRes.ok) {
				const err = await initRes.json();
				throw new Error(err.error || "Init failed");
			}

			const initData = await initRes.json();
			const jobId = initData.job_id;
			const chunkSize = initData.chunk_size || 5 * 1024 * 1024;

			log(
				`Upload initialized: job_id=${jobId}, chunk_size=${formatBytes(
					chunkSize
				)}`
			);

			// 2. Check for resume capability
			const statusUrl = appendServerToUrl(
				`dbimport.php?action=upload_status&job_id=${encodeURIComponent(
					jobId
				)}`
			);
			const statusRes = await fetch(statusUrl);
			const statusData = await statusRes.json();
			let uploaded = statusData.uploaded_bytes || 0;

			if (uploaded > 0) {
				log(`Resuming from offset ${formatBytes(uploaded)}`);
			}

			// 3. Upload chunks with progress
			const uploadProgress = el("uploadProgress");
			while (uploaded < file.size) {
				const chunk = file.slice(uploaded, uploaded + chunkSize);

				// Update progress before sending
				const pct = Math.floor((uploaded / file.size) * 100);
				if (uploadProgress) uploadProgress.value = pct;
				if (uploadStatus) {
					uploadStatus.textContent = `Uploading ${
						file.name
					} - ${pct}% (${formatBytes(uploaded)} / ${formatBytes(
						file.size
					)})`;
				}

				// Upload with retry
				const newUploaded = await uploadChunkWithRetry(
					jobId,
					uploaded,
					chunk
				);
				uploaded = newUploaded;
			}

			// 4. Finalize upload
			if (uploadStatus) {
				uploadStatus.textContent = `Finalizing upload...`;
			}

			const finalUrl = appendServerToUrl(
				`dbimport.php?action=finalize_upload&job_id=${encodeURIComponent(
					jobId
				)}`
			);
			const finalRes = await fetch(finalUrl, { method: "POST" });

			if (!finalRes.ok) {
				const err = await finalRes.json();
				throw new Error(err.error || "Finalize failed");
			}

			const finalData = await finalRes.json();
			log(`Upload complete: ${formatBytes(finalData.size)}`);

			if (uploadStatus) {
				uploadStatus.textContent = `Upload complete (${formatBytes(
					finalData.size
				)}) - Job ID: ${jobId}`;
			}
			if (uploadProgress) uploadProgress.value = 100;

			// Switch to import phase
			if (importPhase) importPhase.style.display = "block";

			// 5. Auto-start or show job list
			const auto = document.getElementById("opt_auto_start")
				? document.getElementById("opt_auto_start").checked
				: false;

			if (auto) {
				// Check for ZIP entries
				const entriesUrl = appendServerToUrl(
					`dbimport.php?action=list_entries&job_id=${encodeURIComponent(
						jobId
					)}`
				);
				const entriesRes = await fetch(entriesUrl);
				const entriesData = await entriesRes.json();

				if (entriesData.entries && entriesData.entries.length > 0) {
					showEntrySelector(
						jobId,
						finalData.size,
						entriesData.entries
					);
				} else {
					runImportLoop(jobId, finalData.size);
				}
			} else {
				refreshEmbeddedJobList();
			}
		} catch (error) {
			alert("Upload error: " + error.message);
			console.error(error);
			if (uploadStatus) {
				uploadStatus.textContent = "Upload failed: " + error.message;
			}
		}
	}

	function showEntrySelector(jobId, totalSize, entries) {
		// Populate static modal and show it
		const modal = document.getElementById("entrySelectorModal");
		const sel = document.getElementById("entrySelect");
		const importAllChk = document.getElementById("import_all_chk");
		if (!modal || !sel) {
			// fallback to dynamic overlay if static modal missing
			console.warn("Static entry selector modal not found");
			return;
		}
		sel.innerHTML = "";
		entries.forEach(function (e) {
			const opt = document.createElement("option");
			opt.value = e.name;
			opt.textContent = e.name + " (" + e.size + " bytes)";
			sel.appendChild(opt);
		});
		// store jobId/totalSize on modal dataset for handler to pick up
		modal.dataset.jobId = jobId;
		modal.dataset.totalSize = totalSize;
		importAllChk.checked = false;
		modal.style.display = "block";
	}

	function log(msg) {
		const p = el("importLog");
		if (!p) return;
		const line = "[" + new Date().toISOString() + "] " + msg + "\n";
		p.textContent = line + p.textContent;
	}

	// Template helper: ensure a <template id="job-row-template"> exists
	function createJobRowFromTemplate(j, updater) {
		let tpl = document.getElementById("job-row-template");

		const frag = tpl.content.cloneNode(true);
		frag.querySelector(".job-info").textContent = `${j.job_id} — ${
			j.status
		} — ${j.offset || 0}/${j.size || 0}`;

		const viewBtn = frag.querySelector(".view");
		const startBtn = frag.querySelector(".start");
		const cancelBtn = frag.querySelector(".cancel");
		const resumeBtn = frag.querySelector(".resume");

		if (viewBtn)
			viewBtn.addEventListener("click", () =>
				showJobStatus(j.job_id, j.size)
			);
		if (startBtn)
			startBtn.addEventListener("click", () =>
				runImportLoop(j.job_id, j.size)
			);
		if (cancelBtn)
			cancelBtn.addEventListener("click", () => {
				fetch(
					appendServerToUrl(
						"dbimport.php?action=cancel_job&job_id=" +
							encodeURIComponent(j.job_id)
					),
					{ method: "POST" }
				).then(() => {
					if (typeof updater === "function") updater();
				});
			});
		if (resumeBtn)
			resumeBtn.addEventListener("click", () => {
				fetch(
					appendServerToUrl(
						"dbimport.php?action=resume_job&job_id=" +
							encodeURIComponent(j.job_id)
					),
					{ method: "POST" }
				).then(() => {
					if (typeof updater === "function") updater();
				});
			});

		return frag;
	}

	async function showJobStatus(jobId, totalSize) {
		try {
			const statusResp = await fetch(
				appendServerToUrl(
					"dbimport.php?action=status&job_id=" +
						encodeURIComponent(jobId)
				)
			);
			if (!statusResp.ok) {
				alert("Failed to load job status");
				return;
			}
			const data = await statusResp.json();
			// Show UI
			const importUI = el("importUI");
			if (importUI) importUI.style.display = "block";
			const uploadPhase = el("uploadPhase");
			const importPhase = el("importPhase");
			if (uploadPhase) uploadPhase.style.display = "none";
			if (importPhase) importPhase.style.display = "block";

			// Populate progress
			if (data.offset && totalSize) {
				const pct = Math.floor((data.offset / totalSize) * 100);
				if (el("importProgress")) el("importProgress").value = pct;
			}
			const importStatus = el("importStatus");
			if (importStatus)
				importStatus.textContent = `${data.status || ""} - Errors: ${
					data.errors || 0
				}`;

			// Populate log
			if (data.log && Array.isArray(data.log)) {
				const importLog = el("importLog");
				if (importLog) {
					importLog.textContent = data.log
						.map((l) => {
							if (l.time && (l.statement || l.info || l.error)) {
								let parts = [];
								if (l.info) parts.push(l.info);
								if (l.statement) parts.push(l.statement);
								if (l.error) parts.push("ERROR: " + l.error);
								return (
									"[" +
									new Date(l.time * 1000).toISOString() +
									"] " +
									parts.join(" - ")
								);
							}
							return JSON.stringify(l);
						})
						.reverse()
						.join("\n");
				}
			}
		} catch (e) {
			console.error(e);
			alert("Failed to fetch job status");
		}
	}

	async function runImportLoop(jobId, totalSize) {
		// Poll: call process endpoint repeatedly until finished
		let running = true;
		let lastOffset = 0;

		// Load existing job status/log before starting so UI shows prior progress
		try {
			const statusResp = await fetch(
				appendServerToUrl(
					"dbimport.php?action=status&job_id=" +
						encodeURIComponent(jobId)
				)
			);
			if (statusResp.ok) {
				const sdata = await statusResp.json();
				// If the job is cancelled on server, attempt to resume it first
				if (sdata.status === "cancelled") {
					try {
						const resumeResp = await fetch(
							appendServerToUrl(
								"dbimport.php?action=resume_job&job_id=" +
									encodeURIComponent(jobId)
							)
						);
						if (resumeResp.ok) {
							// refresh job list so UI reflects resumed state
							try {
								refreshEmbeddedJobList();
							} catch (e) {}
							// reload status
							const newStatus = await (
								await fetch(
									appendServerToUrl(
										"dbimport.php?action=status&job_id=" +
											encodeURIComponent(jobId)
									)
								)
							).json();
							Object.assign(sdata, newStatus);
						}
					} catch (e) {
						// ignore resume failure, proceed with existing state which may be 'cancelled'
					}
				}
				const importLog = el("importLog");
				if (importLog && sdata.log && Array.isArray(sdata.log)) {
					importLog.textContent = sdata.log
						.map((l) => {
							if (l.time && (l.statement || l.info || l.error)) {
								let parts = [];
								if (l.info) parts.push(l.info);
								if (l.statement) parts.push(l.statement);
								if (l.error) parts.push("ERROR: " + l.error);
								return (
									"[" +
									new Date(l.time * 1000).toISOString() +
									"] " +
									parts.join(" - ")
								);
							}

							return JSON.stringify(l);
						})
						.reverse()
						.join("\n");
				}
				if (sdata.offset && totalSize) {
					const pct = Math.floor((sdata.offset / totalSize) * 100);
					if (el("importProgress")) el("importProgress").value = pct;
				}
			}
		} catch (e) {
			// ignore
		}

		// Refresh embedded job list to reflect the job entering processing
		try {
			refreshEmbeddedJobList();
		} catch (e) {
			// ignore
		}
		async function step() {
			try {
				const procUrl = appendServerToUrl(
					"dbimport.php?action=process&job_id=" +
						encodeURIComponent(jobId)
				);
				const resp = await fetch(procUrl, { method: "POST" });
				if (!resp.ok) {
					log("Process request failed: " + resp.status);
					return false;
				}
				const data = await resp.json();
				if (data.offset && totalSize) {
					const pct = Math.floor((data.offset / totalSize) * 100);
					if (el("importProgress")) el("importProgress").value = pct;
					const importStatus = el("importStatus");
					if (importStatus) {
						let statusText = `Processing: ${pct}% (${formatBytes(
							data.offset
						)} / ${formatBytes(totalSize)})`;
						if (data.current_db) {
							statusText += ` - DB: ${data.current_db}`;
						}
						if (data.errors) {
							statusText += ` - Errors: ${data.errors}`;
						}
						importStatus.textContent = statusText;
					}
					log(
						`Processed ${formatBytes(data.offset)} / ${formatBytes(
							totalSize
						)} (${pct}%)`
					);
				}
				// Update visible log (server returns full log array)
				if (data.log && Array.isArray(data.log)) {
					const importLog = el("importLog");
					if (importLog) {
						importLog.textContent = data.log
							.map((l) => {
								if (
									l.time &&
									(l.statement || l.info || l.error)
								) {
									let parts = [];
									if (l.info) parts.push(l.info);
									if (l.statement) parts.push(l.statement);
									if (l.error)
										parts.push("ERROR: " + l.error);
									return (
										"[" +
										new Date(l.time * 1000).toISOString() +
										"] " +
										parts.join(" - ")
									);
								}
								return JSON.stringify(l);
							})
							.reverse()
							.join("\n");
					}
				}

				// Stop conditions: finished, error, or offset >= size
				if (
					data.status === "finished" ||
					data.status === "error" ||
					(totalSize && data.offset >= totalSize)
				) {
					const importStatus = el("importStatus");
					if (importStatus) {
						importStatus.textContent = `Import complete - Errors: ${
							data.errors || 0
						}`;
					}
					log(
						"Import finished for job " +
							jobId +
							" with errors: " +
							(data.errors || 0)
					);
					return false; // stop
				}
				return true; // continue
			} catch (e) {
				log("Process error: " + e);
				return false;
			}
		}

		// loop with small delay to avoid hammering
		while (running) {
			const cont = await step();
			if (!cont) break;
			await new Promise((r) => setTimeout(r, 700));
		}

		// Update job list after processing completes/stops
		try {
			refreshEmbeddedJobList();
		} catch (e) {
			// ignore
		}
	}

	document.addEventListener("frameLoaded", function () {
		const btn = el("importStart");
		if (btn) btn.addEventListener("click", startUpload);

		// add a small Jobs button to open job list
		let jobsBtn = document.getElementById("importJobsBtn");
		if (!jobsBtn) {
			jobsBtn = document.createElement("button");
			jobsBtn.id = "importJobsBtn";
			jobsBtn.textContent = "Import Jobs";
			jobsBtn.style.marginLeft = "8px";
			if (btn && btn.parentNode)
				btn.parentNode.insertBefore(jobsBtn, btn.nextSibling);
			jobsBtn.addEventListener("click", openJobList);
		}

		// populate embedded uploaded-jobs list on load
		refreshEmbeddedJobList();

		// Attach handlers for static modals (if present)
		const entryImportBtn = document.getElementById("entryImportBtn");
		const entryCancelBtn = document.getElementById("entryCancelBtn");
		const jobListClose = document.getElementById("jobListClose");
		if (entryImportBtn) {
			entryImportBtn.addEventListener("click", function () {
				const modal = document.getElementById("entrySelectorModal");
				if (!modal) return;
				const jobId = modal.dataset.jobId;
				const totalSize = modal.dataset.totalSize || 0;
				const sel = document.getElementById("entrySelect");
				const importAll =
					document.getElementById("import_all_chk").checked;
				const params = new URLSearchParams();
				params.append("job_id", jobId);
				if (importAll) params.append("import_all", "1");
				else params.append("entry", sel.value);
				appendServerToParams(params);
				fetch(appendServerToUrl("dbimport.php?action=select_entry"), {
					method: "POST",
					body: params,
				})
					.then((r) => r.json())
					.then(() => {
						modal.style.display = "none";
						runImportLoop(jobId, totalSize);
					})
					.catch(() => alert("Failed to select entry"));
			});
		}
		if (entryCancelBtn) {
			entryCancelBtn.addEventListener("click", function () {
				document.getElementById("entrySelectorModal").style.display =
					"none";
			});
		}
		if (jobListClose) {
			jobListClose.addEventListener("click", function () {
				document.getElementById("jobListModal").style.display = "none";
			});
		}
	});

	function openJobList() {
		const showAllParam =
			document.getElementById("opt_show_all") &&
			document.getElementById("opt_show_all").checked
				? "&show_all=1"
				: "";
		fetch(appendServerToUrl("dbimport.php?action=list_jobs" + showAllParam))
			.then((r) => r.json())
			.then((data) => {
				const jobs = data && data.jobs ? data.jobs : [];
				const container = document.getElementById("jobListContainer");
				if (!container) return;
				container.innerHTML = "";
				if (jobs.length === 0) {
					container.textContent = "No jobs";
				} else {
					jobs.forEach((j) => {
						const node = createJobRowFromTemplate(j, openJobList);
						container.appendChild(node);
					});
				}
				document.getElementById("jobListModal").style.display = "block";
			})
			.catch((e) => alert("Failed to list jobs: " + e));
	}

	function refreshEmbeddedJobList() {
		const container = document.getElementById("uploadedJobsList");
		if (!container) return;
		container.textContent = "Loading...";
		const showAllParam2 =
			document.getElementById("opt_show_all") &&
			document.getElementById("opt_show_all").checked
				? "&show_all=1"
				: "";
		fetch(
			appendServerToUrl("dbimport.php?action=list_jobs" + showAllParam2)
		)
			.then((r) => r.json())
			.then((data) => {
				const jobs = data && data.jobs ? data.jobs : [];
				container.innerHTML = "";
				if (jobs.length === 0) {
					container.textContent = "No uploaded jobs";
					return;
				}
				jobs.forEach((j) => {
					const node = createJobRowFromTemplate(
						j,
						refreshEmbeddedJobList
					);
					container.appendChild(node);
				});
			})
			.catch((e) => {
				container.textContent = "Failed to load jobs";
				console.error(e);
			});
	}
})();
