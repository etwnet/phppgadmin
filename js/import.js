// Simple uploader + chunked import controller
(function () {
	function el(id) {
		return document.getElementById(id);
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

		// client-side check for configured max upload size (if provided by renderer)
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

		// sniff magic bytes client-side to prevent uploading unsupported compressed files
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
			uploadStatus.textContent = `Uploading ${file.name} (${formatBytes(
				file.size
			)})...`;
		}

		const fd = new FormData();
		fd.append("file", file);
		fd.append("scope", scope);
		fd.append("scope_ident", scope_ident);

		const xhr = new XMLHttpRequest();
		xhr.open("POST", "dbimport.php?action=upload", true);

		xhr.upload.onprogress = function (ev) {
			if (ev.lengthComputable) {
				const pct = Math.floor((ev.loaded / ev.total) * 100);
				if (el("uploadProgress")) el("uploadProgress").value = pct;
				const uploadStatus = el("uploadStatus");
				if (uploadStatus) {
					uploadStatus.textContent = `Uploading ${
						file.name
					} - ${pct}% (${formatBytes(ev.loaded)} / ${formatBytes(
						ev.total
					)})`;
				}
			}
		};

		xhr.onload = function () {
			if (xhr.status >= 200 && xhr.status < 300) {
				let res;
				try {
					res = JSON.parse(xhr.responseText);
				} catch (e) {
					alert("Invalid response");
					return;
				}
				if (res.job_id) {
					log("Upload finished, job_id=" + res.job_id);
					const uploadStatus = el("uploadStatus");
					if (uploadStatus) {
						uploadStatus.textContent = `Upload complete (${formatBytes(
							res.size || 0
						)}) - Job ID: ${res.job_id}`;
					}
					// Switch to import phase
					const uploadPhase = el("uploadPhase");
					const importPhase = el("importPhase");
					if (importPhase) importPhase.style.display = "block";

					const auto = document.getElementById("opt_auto_start")
						? document.getElementById("opt_auto_start").checked
						: false;
					if (auto) {
						// try to list entries and start immediately if needed
						fetch(
							"dbimport.php?action=list_entries&job_id=" +
								encodeURIComponent(res.job_id)
						)
							.then((r) => r.json())
							.then((info) => {
								if (
									info &&
									info.entries &&
									info.entries.length > 0
								) {
									showEntrySelector(
										res.job_id,
										res.size,
										info.entries
									);
								} else {
									runImportLoop(res.job_id, res.size);
								}
							})
							.catch(() => runImportLoop(res.job_id, res.size));
					} else {
						// refresh embedded job list for manual control
						refreshEmbeddedJobList();
					}
				} else if (res.error) {
					alert("Upload error: " + res.error);
				}
			} else {
				alert("Upload failed: " + xhr.status);
			}
		};

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

		xhr.onerror = function () {
			alert("Upload error");
		};
		xhr.send(fd);
	}

	function log(msg) {
		const p = el("importLog");
		if (!p) return;
		const line = "[" + new Date().toISOString() + "] " + msg + "\n";
		p.textContent = line + p.textContent;
	}

	async function runImportLoop(jobId, totalSize) {
		// Poll: call process endpoint repeatedly until finished
		let running = true;
		let lastOffset = 0;
		async function step() {
			try {
				const resp = await fetch(
					"dbimport.php?action=process&job_id=" +
						encodeURIComponent(jobId),
					{ method: "POST" }
				);
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
				if (data.status === "finished") {
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
				fetch("dbimport.php?action=select_entry", {
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
		fetch("dbimport.php?action=list_jobs" + showAllParam)
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
						const row = document.createElement("div");
						row.style.display = "flex";
						row.style.justifyContent = "space-between";
						row.style.padding = "6px 0";
						row.style.borderBottom = "1px solid #eee";
						const info = document.createElement("div");
						info.textContent = `${j.job_id} — ${j.status} — ${
							j.offset || 0
						}/${j.size || 0}`;
						const actions = document.createElement("div");
						const cancel = document.createElement("button");
						cancel.textContent = "Cancel";
						cancel.style.marginRight = "8px";
						cancel.addEventListener("click", () => {
							fetch(
								"dbimport.php?action=cancel_job&job_id=" +
									encodeURIComponent(j.job_id),
								{ method: "POST" }
							).then(() => openJobList());
						});
						const resume = document.createElement("button");
						resume.textContent = "Resume";
						resume.addEventListener("click", () => {
							fetch(
								"dbimport.php?action=resume_job&job_id=" +
									encodeURIComponent(j.job_id),
								{ method: "POST" }
							).then(() => openJobList());
						});
						actions.appendChild(cancel);
						actions.appendChild(resume);
						row.appendChild(info);
						row.appendChild(actions);
						container.appendChild(row);
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
		fetch("dbimport.php?action=list_jobs" + showAllParam2)
			.then((r) => r.json())
			.then((data) => {
				const jobs = data && data.jobs ? data.jobs : [];
				container.innerHTML = "";
				if (jobs.length === 0) {
					container.textContent = "No uploaded jobs";
					return;
				}
				jobs.forEach((j) => {
					const row = document.createElement("div");
					row.style.display = "flex";
					row.style.justifyContent = "space-between";
					row.style.padding = "6px 0";
					row.style.borderBottom = "1px solid #eee";
					const info = document.createElement("div");
					info.textContent = `${j.job_id} — ${j.status} — ${
						j.offset || 0
					}/${j.size || 0}`;
					const actions = document.createElement("div");
					const start = document.createElement("button");
					start.textContent = "Start";
					start.style.marginRight = "8px";
					start.addEventListener("click", () =>
						runImportLoop(j.job_id, j.size)
					);
					const cancel = document.createElement("button");
					cancel.textContent = "Cancel";
					cancel.style.marginRight = "8px";
					cancel.addEventListener("click", () => {
						fetch(
							"dbimport.php?action=cancel_job&job_id=" +
								encodeURIComponent(j.job_id),
							{ method: "POST" }
						).then(() => refreshEmbeddedJobList());
					});
					const resume = document.createElement("button");
					resume.textContent = "Resume";
					resume.addEventListener("click", () => {
						fetch(
							"dbimport.php?action=resume_job&job_id=" +
								encodeURIComponent(j.job_id),
							{ method: "POST" }
						).then(() => refreshEmbeddedJobList());
					});
					actions.appendChild(start);
					actions.appendChild(cancel);
					actions.appendChild(resume);
					row.appendChild(info);
					row.appendChild(actions);
					container.appendChild(row);
				});
			})
			.catch((e) => {
				container.textContent = "Failed to load jobs";
			});
	}
})();
