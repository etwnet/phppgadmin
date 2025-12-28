import {
	fnv1a64,
	formatBytes,
	sniffMagicType,
	getServerCaps,
	el,
	log,
} from "./utils.js";
import { appendServerToUrl, appendServerToParams } from "./api.js";
import { runImportLoop } from "./importer.js";
import { refreshEmbeddedJobList } from "./jobs.js";

let isUploadPaused = false;
let currentUploadJobId = null;
let uploadResolveResume = null;

export async function uploadChunkWithRetry(
	jobId,
	offset,
	chunk,
	maxRetries = 3
) {
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
				if (res.status === "OK") return res.uploaded_bytes;
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
			console.warn(`Retry ${attempt}/${maxRetries} for chunk @${offset}`);
			await new Promise((r) => setTimeout(r, 500 * attempt));
		}
	}
}

function showEntrySelector(jobId, totalSize, entries) {
	const modal = document.getElementById("entrySelectorModal");
	const sel = document.getElementById("entrySelect");
	const importAllChk = document.getElementById("import_all_chk");
	if (!modal || !sel) {
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
	modal.dataset.jobId = jobId;
	modal.dataset.totalSize = totalSize;
	importAllChk.checked = false;
	modal.style.display = "block";
}

export async function startUpload() {
	// Guarding
	if (typeof window === "undefined") return;

	const activeImportJobId = null; // importer manages its own active id
	if (activeImportJobId) {
		alert("Cannot start upload while an import is running.");
		return;
	}

	try {
		const fileInput = el("file");
		if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
			alert("No file selected");
			return;
		}
		const file = fileInput.files[0];

		isUploadPaused = false;
		currentUploadJobId = null;
		if (uploadResolveResume) {
			uploadResolveResume(false);
			uploadResolveResume = null;
		}

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

		const importUI = el("importUI");
		const uploadPhase = el("uploadPhase");
		const importPhase = el("importPhase");
		if (importUI) importUI.style.display = "block";
		if (uploadPhase) uploadPhase.style.display = "block";
		if (importPhase) importPhase.style.display = "none";

		const uploadStatus = el("uploadStatus");
		const pauseBtn = el("uploadPauseBtn");
		const cancelBtn = el("uploadCancelBtn");

		if (uploadStatus) {
			uploadStatus.textContent = `Initializing upload for ${
				file.name
			} (${formatBytes(file.size)})...`;
		}
		if (pauseBtn) {
			pauseBtn.style.display = "inline-block";
			pauseBtn.textContent = "Pause";
			pauseBtn.onclick = function () {
				if (isUploadPaused) {
					isUploadPaused = false;
					pauseBtn.textContent = "Pause";
					if (uploadResolveResume) {
						uploadResolveResume(true);
						uploadResolveResume = null;
					}
				} else {
					isUploadPaused = true;
					pauseBtn.textContent = "Resume";
					if (uploadStatus) uploadStatus.textContent += " (Paused)";
				}
			};
		}
		if (cancelBtn) {
			cancelBtn.style.display = "inline-block";
			cancelBtn.onclick = function () {
				if (!confirm("Cancel upload?")) return;
				isUploadPaused = true;
				if (uploadResolveResume) {
					uploadResolveResume(false);
					uploadResolveResume = null;
				}
				const fileKey = `upload_job_${file.name}_${file.size}_${file.lastModified}`;
				localStorage.removeItem(fileKey);
				if (importUI) importUI.style.display = "none";
				alert("Upload cancelled");
				refreshEmbeddedJobList();
			};
		}

		const fileKey = `upload_job_${file.name}_${file.size}_${file.lastModified}`;
		let jobId = localStorage.getItem(fileKey);
		let chunkSize = 5 * 1024 * 1024;

		if (jobId) {
			try {
				const statusUrl = appendServerToUrl(
					`dbimport.php?action=upload_status&job_id=${encodeURIComponent(
						jobId
					)}`
				);
				const statusRes = await fetch(statusUrl);
				const statusData = await statusRes.json();
				if (
					statusData.uploaded_bytes !== undefined &&
					statusData.uploaded_bytes < file.size
				) {
					log(
						`Found existing job ${jobId}, checking status...`,
						"upload"
					);
				} else {
					jobId = null;
				}
			} catch (e) {
				jobId = null;
			}
		}

		if (!jobId) {
			const formData = new URLSearchParams();
			formData.append("filename", file.name);
			formData.append("filesize", file.size);
			formData.append("scope", scope);
			formData.append("scope_ident", scope_ident);

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
				if (chk && chk.checked) formData.append(opt, "1");
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
				{ method: "POST", body: formData }
			);
			if (!initRes.ok) {
				const err = await initRes.json();
				throw new Error(err.error || "Init failed");
			}
			const initData = await initRes.json();
			jobId = initData.job_id;
			chunkSize = initData.chunk_size || 5 * 1024 * 1024;
			localStorage.setItem(fileKey, jobId);

			log(
				`Upload initialized: job_id=${jobId}, chunk_size=${formatBytes(
					chunkSize
				)}`,
				"upload"
			);
		}

		currentUploadJobId = jobId;

		const statusUrl = appendServerToUrl(
			`dbimport.php?action=upload_status&job_id=${encodeURIComponent(
				jobId
			)}`
		);
		const statusRes = await fetch(statusUrl);
		const statusData = await statusRes.json();
		let uploaded = statusData.uploaded_bytes || 0;

		if (uploaded > 0)
			log(`Resuming from offset ${formatBytes(uploaded)}`, "upload");

		const uploadProgress = el("uploadProgress");
		while (uploaded < file.size) {
			if (isUploadPaused) {
				log("Upload paused. Waiting for resume...", "upload");
				const shouldResume = await new Promise((resolve) => {
					uploadResolveResume = resolve;
				});
				if (!shouldResume) throw new Error("Upload cancelled by user");
				log("Upload resumed", "upload");
			}

			const chunk = file.slice(uploaded, uploaded + chunkSize);
			const pct = Math.floor((uploaded / file.size) * 100);
			if (uploadProgress) uploadProgress.value = pct;
			if (uploadStatus)
				uploadStatus.textContent = `Uploading ${
					file.name
				} - ${pct}% (${formatBytes(uploaded)} / ${formatBytes(
					file.size
				)})`;

			const newUploaded = await uploadChunkWithRetry(
				jobId,
				uploaded,
				chunk
			);
			uploaded = newUploaded;
		}

		localStorage.removeItem(fileKey);
		if (el("uploadPauseBtn")) el("uploadPauseBtn").style.display = "none";
		if (el("uploadCancelBtn")) el("uploadCancelBtn").style.display = "none";

		if (uploadStatus) uploadStatus.textContent = "Finalizing upload...";

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
		log(`Upload complete: ${formatBytes(finalData.size)}`, "upload");
		if (uploadStatus)
			uploadStatus.textContent = `Upload complete (${formatBytes(
				finalData.size
			)}) - Job ID: ${jobId}`;
		if (uploadProgress) uploadProgress.value = 100;

		if (el("importPhase")) el("importPhase").style.display = "block";

		const auto = document.getElementById("opt_auto_start")
			? document.getElementById("opt_auto_start").checked
			: false;
		if (auto) {
			const entriesUrl = appendServerToUrl(
				`dbimport.php?action=list_entries&job_id=${encodeURIComponent(
					jobId
				)}`
			);
			const entriesRes = await fetch(entriesUrl);
			const entriesData = await entriesRes.json();
			if (entriesData.entries && entriesData.entries.length > 0) {
				showEntrySelector(jobId, finalData.size, entriesData.entries);
			} else {
				runImportLoop(jobId, finalData.size);
			}
		} else {
			refreshEmbeddedJobList();
		}
	} catch (error) {
		alert("Upload error: " + error.message);
		console.error(error);
		if (el("uploadStatus"))
			el("uploadStatus").textContent = "Upload failed: " + error.message;
	} finally {
		currentUploadJobId = null;
	}
}
