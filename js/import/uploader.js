import {
	fnv1a64,
	formatBytes,
	sniffMagicType,
	getServerCaps,
	el,
	val,
	qsa,
	log,
} from "./utils.js";
import { appendServerToUrl, appendServerToParams } from "./api.js";
import { runImportLoop } from "./importer.js";
import { refreshEmbeddedJobList } from "./jobs.js";

// Dynamically import fflate for decompression/compression
let fflate = null;
async function ensureFflate() {
	if (!fflate) {
		fflate = await import("../lib/fflate/esm/browser.js");
	}
	return fflate;
}

let isUploadPaused = false;
let uploadResolveResume = null;
let currentChunkController = null;
let uploadCancelledByUser = false;

const ui = {
	fileInput: null,
	importUI: null,
	uploadPhase: null,
	importPhase: null,
	uploadStatus: null,
	uploadPauseBtn: null,
	uploadCancelBtn: null,
	uploadProgress: null,
	entrySelectorModal: null,
	entrySelect: null,
	importAllChk: null,
};

const current = {
	file: null,
	jobId: null,
	fileKey: null,
	chunkSize: 5 * 1024 * 1024, // Uncompressed chunk size
	uploaded: 0,
	compressedBytesRead: 0, // For progress tracking with compressed files
	totalCompressedSize: 0, // Original file size if compressed
	chunkNumber: 0, // Sequential chunk number for chunked format
};

function cacheUI() {
	ui.fileInput = el("file");
	ui.importUI = el("importUI");
	ui.uploadPhase = el("uploadPhase");
	ui.importPhase = el("importPhase");
	ui.uploadStatus = el("uploadStatus");
	ui.uploadPauseBtn = el("uploadPauseBtn");
	ui.uploadCancelBtn = el("uploadCancelBtn");
	ui.uploadProgress = el("uploadProgress");

	// optional (only used when auto-starting)
	ui.entrySelectorModal = el("entrySelectorModal");
	ui.entrySelect = el("entrySelect");
	ui.importAllChk = el("import_all_chk");
}

export async function uploadChunkWithRetry(
	jobId,
	chunkNumber,
	chunkData,
	uncompressedSize
) {
	const buf = new Uint8Array(chunkData);
	const checksum = fnv1a64(buf);

	try {
		const controller = new AbortController();
		currentChunkController = controller;
		let _url = `dbimport.php?action=upload_chunk&job_id=${encodeURIComponent(
			jobId
		)}&chunk_number=${chunkNumber}`;
		_url = appendServerToUrl(_url);

		const resp = await fetch(_url, {
			method: "POST",
			body: buf,
			headers: {
				"X-Checksum": checksum,
				"X-Uncompressed-Size": uncompressedSize.toString(),
				"Content-Type": "application/octet-stream",
			},
			signal: controller.signal,
		});

		currentChunkController = null;

		if (resp.ok) {
			const res = await resp.json();
			if (res.status === "OK") return res.chunk_number;
			if (res.status === "BAD_CHECKSUM") {
				throw new Error("BAD_CHECKSUM");
			}
			throw new Error(res.error || "Upload failed");
		}
		const responseText = await resp.text();
		console.error("[Upload] Server error response:", responseText);
		throw new Error(`HTTP ${resp.status}`);
	} catch (e) {
		currentChunkController = null;
		// If aborted by user, propagate immediately without retries
		if (e.name === "AbortError" || e.message === "aborted") {
			throw new Error("aborted");
		}
		throw e;
	}
}

function showEntrySelector(jobId, totalSize, entries) {
	// CHANGED: prefer cached UI; fall back gracefully
	const modal = ui.entrySelectorModal || el("entrySelectorModal");
	const sel = ui.entrySelect || el("entrySelect");
	const importAllChk = ui.importAllChk || el("import_all_chk");
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

async function validateFileAndCaps() {
	// CHANGED: uses module state instead of parameters
	const fileInput = ui.fileInput;
	const file = current.file;
	if (!fileInput || !file) throw new Error("No file selected");

	const maxAttr = fileInput.dataset ? fileInput.dataset.importMaxSize : null;
	if (maxAttr) {
		const maxSize = parseInt(maxAttr, 10) || 0;
		if (maxSize > 0 && file.size > maxSize) {
			throw new Error(
				"Selected file exceeds maximum allowed upload size."
			);
		}
	}

	const caps = getServerCaps(fileInput);
	const detectedType = await sniffMagicType(file);
	if (detectedType === "zip" && !caps.zip) {
		throw new Error(
			"This file appears to be a ZIP archive, but ZIP support is not available on the server (PHP ext-zip / ZipArchive)."
		);
	}
	if (detectedType === "gzip" && !caps.gzip) {
		throw new Error(
			"This file appears to be gzip-compressed, but gzip support is not available on the server (PHP ext-zlib)."
		);
	}
	if (detectedType === "bzip2" && !caps.bzip2) {
		throw new Error(
			"This file appears to be bzip2-compressed, but bzip2 support is not available on the server (PHP ext-bz2)."
		);
	}
}

function setupUploadUI() {
	// CHANGED: uses module UI cache + current.file
	const file = current.file;

	if (ui.importUI) ui.importUI.style.display = "block";
	if (ui.uploadPhase) ui.uploadPhase.style.display = "block";
	if (ui.importPhase) ui.importPhase.style.display = "none";

	if (ui.uploadStatus && file) {
		ui.uploadStatus.textContent = `Initializing upload for ${
			file.name
		} (${formatBytes(file.size)})...`;
	}
}

function setupUploadButtons() {
	// CHANGED: uses module state; no parameter passing
	const file = current.file;

	if (ui.uploadPauseBtn) {
		ui.uploadPauseBtn.style.display = "inline-block";
		ui.uploadPauseBtn.textContent = "Pause";
		ui.uploadPauseBtn.onclick = function () {
			if (isUploadPaused) {
				isUploadPaused = false;
				ui.uploadPauseBtn.textContent = "Pause";
				if (uploadResolveResume) {
					uploadResolveResume(true);
					uploadResolveResume = null;
				}
			} else {
				isUploadPaused = true;
				ui.uploadPauseBtn.textContent = "Resume";
				if (
					ui.uploadStatus &&
					!ui.uploadStatus.textContent.includes("(Paused)")
				) {
					ui.uploadStatus.textContent += " (Paused)";
				}
			}
		};
	}

	if (ui.uploadCancelBtn) {
		ui.uploadCancelBtn.style.display = "inline-block";
		ui.uploadCancelBtn.onclick = async function () {
			if (!confirm("Cancel upload?")) return;

			uploadCancelledByUser = true;
			isUploadPaused = true;

			if (uploadResolveResume) {
				uploadResolveResume(false);
				uploadResolveResume = null;
			}

			try {
				if (currentChunkController) currentChunkController.abort();
			} catch (e) {}

			try {
				if (current.jobId) {
					const delUrl = appendServerToUrl(
						`dbimport.php?action=delete_job&job_id=${encodeURIComponent(
							current.jobId
						)}`
					);
					await fetch(delUrl, { method: "POST" });
				}
			} catch (e) {
				console.warn("Server-side abort failed", e);
			}

			if (current.fileKey) localStorage.removeItem(current.fileKey);
			if (ui.importUI) ui.importUI.style.display = "none";

			try {
				refreshEmbeddedJobList();
			} catch (e) {}
		};
	}
}

async function initOrResumeJob(scope, scope_ident) {
	// CHANGED: uses current.file; writes into current.*
	const file = current.file;
	if (!file) throw new Error("No file selected");

	const fileKey = `upload_job_${file.name}_${file.size}_${file.lastModified}`;
	current.fileKey = fileKey;

	let jobId = localStorage.getItem(fileKey);
	let chunkSize = current.chunkSize;

	if (jobId) {
		console.log("[Init] Found cached job_id:", jobId);
		try {
			const statusUrl = appendServerToUrl(
				`dbimport.php?action=upload_status&job_id=${encodeURIComponent(
					jobId
				)}`
			);
			const statusRes = await fetch(statusUrl);
			const statusData = await statusRes.json();

			// Check for chunked format resume
			if (
				statusData.chunks !== undefined &&
				Array.isArray(statusData.chunks)
			) {
				current.jobId = jobId;
				current.chunkNumber = statusData.chunks.length; // Resume from next chunk
				if (statusData.chunk_size)
					current.chunkSize = statusData.chunk_size;

				log(
					`Found existing job ${jobId}, resuming from chunk ${current.chunkNumber}`,
					"upload"
				);
				return;
			}

			jobId = null;
		} catch (e) {
			console.log(
				"[Init] Cached job not found or invalid, will create new:",
				e.message
			);
			jobId = null;
		}
	}

	if (!jobId) {
		console.log("[Init] Creating new job");
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
			const chk = el(opt);
			if (chk && chk.checked) formData.append(opt, "1");
		});
		const errorModeRadios = qsa('[name="opt_error_mode"]');
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
		current.jobId = initData.job_id;
		current.chunkSize = initData.chunk_size || chunkSize;
		current.chunkNumber = 0;

		localStorage.setItem(fileKey, current.jobId);

		log(
			`Upload initialized: job_id=${
				current.jobId
			}, chunk_size=${formatBytes(current.chunkSize)}`,
			"upload"
		);
	}
}

async function uploadAllChunks() {
	const file = current.file;
	const jobId = current.jobId;
	const targetChunkSize = current.chunkSize; // 5MB uncompressed
	let chunkNumber = current.chunkNumber || 0;

	if (!file || !jobId) throw new Error("Upload not initialized");

	if (chunkNumber > 0) {
		log(`Resuming from chunk ${chunkNumber}`, "upload");
	}

	// Detect file format
	const magic = await sniffMagicType(file);
	const isGzip = magic === "gzip";

	current.totalCompressedSize = file.size;
	current.compressedBytesRead = 0;

	if (isGzip) {
		log(`Detected gzip input, will decompress and re-chunk`, "upload");
		await uploadGzipFileChunked(file, jobId, targetChunkSize, chunkNumber);
	} else {
		log(`Uploading plain file in chunks`, "upload");
		await uploadPlainFileChunked(file, jobId, targetChunkSize, chunkNumber);
	}
}

/**
 * Upload a plain (uncompressed) file by chunking and compressing
 */
async function uploadPlainFileChunked(
	file,
	jobId,
	targetChunkSize,
	startChunkNumber
) {
	const ff = await ensureFflate();
	let chunkNumber = startChunkNumber;
	let offset = chunkNumber * targetChunkSize;

	while (offset < file.size) {
		if (isUploadPaused) {
			log("Upload paused. Waiting for resume...", "upload");
			const shouldResume = await new Promise((resolve) => {
				uploadResolveResume = resolve;
			});
			if (!shouldResume) throw new Error("Upload cancelled by user");
			log("Upload resumed", "upload");
		}

		const end = Math.min(offset + targetChunkSize, file.size);
		const blob = file.slice(offset, end);
		const uncompressedData = new Uint8Array(await blob.arrayBuffer());
		const uncompressedSize = uncompressedData.length;

		// Compress with gzip level 3
		const compressedData = ff.gzipSync(uncompressedData, { level: 3 });

		const pct = Math.floor((offset / file.size) * 100);
		if (ui.uploadProgress) ui.uploadProgress.value = pct;
		if (ui.uploadStatus) {
			ui.uploadStatus.textContent = `Uploading ${
				file.name
			} - ${pct}% (${formatBytes(offset)} / ${formatBytes(file.size)})`;
		}

		// Upload with retry logic
		let attemptDelay = 1000;
		while (true) {
			try {
				await uploadChunkWithRetry(
					jobId,
					chunkNumber,
					compressedData,
					uncompressedSize
				);
				chunkNumber++;
				current.chunkNumber = chunkNumber;
				offset = end;
				break;
			} catch (e) {
				if (uploadCancelledByUser || e.message === "aborted") {
					throw new Error("Upload cancelled by user");
				}

				console.warn("Chunk upload failed, will retry:", e);

				if (ui.uploadStatus) {
					ui.uploadStatus.textContent = `Upload interrupted — retrying in ${Math.round(
						attemptDelay / 1000
					)}s...`;
				}

				if (typeof navigator !== "undefined" && !navigator.onLine) {
					if (ui.uploadStatus)
						ui.uploadStatus.textContent =
							"Offline — waiting to reconnect...";
					await new Promise((resolve) => {
						const onOnline = () => {
							window.removeEventListener("online", onOnline);
							resolve();
						};
						window.addEventListener("online", onOnline);
					});
				} else {
					await new Promise((r) => setTimeout(r, attemptDelay));
					attemptDelay = Math.min(attemptDelay * 2, 60000);
				}
			}
		}
	}
}

/**
 * Upload a gzip file by streaming decompression, chunking, and re-compression
 * Uses true streaming to handle multi-gigabyte files without loading into memory
 */
async function uploadGzipFileChunked(
	file,
	jobId,
	targetChunkSize,
	startChunkNumber
) {
	const ff = await ensureFflate();
	let chunkNumber = startChunkNumber;
	let uncompressedBuffer = new Uint8Array(0);
	let compressedBytesRead = 0;
	let decompressedTotal = 0;

	log(`Starting streaming decompression...`, "upload");

	// Create streaming gunzip decompressor with ondata callback
	const gunzip = new ff.Gunzip();
	gunzip.ondata = (data, final) => {
		// Note: fflate signature is (data, final), not (err, data, final)
		// Accumulate decompressed data
		const newBuffer = new Uint8Array(
			uncompressedBuffer.length + data.length
		);
		newBuffer.set(uncompressedBuffer);
		newBuffer.set(data, uncompressedBuffer.length);
		uncompressedBuffer = newBuffer;
		decompressedTotal += data.length;
		console.log(
			`[Decompress] Received ${data.length} bytes, total: ${decompressedTotal}`
		);
	};

	// Read compressed file in 1MB chunks
	const readChunkSize = 1 * 1024 * 1024;
	let fileOffset = 0;

	log(`Starting streaming decompression and upload...`, "upload");

	while (fileOffset < file.size || uncompressedBuffer.length > 0) {
		if (isUploadPaused) {
			log("Upload paused. Waiting for resume...", "upload");
			const shouldResume = await new Promise((resolve) => {
				uploadResolveResume = resolve;
			});
			if (!shouldResume) throw new Error("Upload cancelled by user");
			log("Upload resumed", "upload");
		}

		// Read and decompress more data if we don't have enough uncompressed yet
		while (
			uncompressedBuffer.length < targetChunkSize &&
			fileOffset < file.size
		) {
			const end = Math.min(fileOffset + readChunkSize, file.size);
			const blob = file.slice(fileOffset, end);
			const compressedChunk = new Uint8Array(await blob.arrayBuffer());

			// Push to decompressor (it will call our ondata callback)
			const isFinal = fileOffset + compressedChunk.length >= file.size;
			gunzip.push(compressedChunk, isFinal);

			fileOffset = end;
			compressedBytesRead = fileOffset;

			// Update progress based on compressed bytes read
			const pct = Math.floor((compressedBytesRead / file.size) * 100);
			if (ui.uploadProgress) ui.uploadProgress.value = pct;
			if (ui.uploadStatus) {
				ui.uploadStatus.textContent = `Processing ${
					file.name
				} - ${pct}% (${formatBytes(decompressedTotal)} decompressed)`;
			}
		}

		// If we have enough data (or finished reading and have remainder), upload a chunk
		if (
			uncompressedBuffer.length >= targetChunkSize ||
			(fileOffset >= file.size && uncompressedBuffer.length > 0)
		) {
			const chunkSize = Math.min(
				targetChunkSize,
				uncompressedBuffer.length
			);
			const chunkData = uncompressedBuffer.slice(0, chunkSize);
			uncompressedBuffer = uncompressedBuffer.slice(chunkSize);
			const uncompressedSize = chunkData.length;

			// Compress this chunk with gzip level 3
			const compressedChunk = ff.gzipSync(chunkData, { level: 3 });

			console.log(
				`[Upload] Chunk ${chunkNumber}: ${uncompressedSize} bytes -> ${compressedChunk.length} bytes compressed`
			);

			// Upload with retry logic
			let attemptDelay = 1000;
			while (true) {
				try {
					await uploadChunkWithRetry(
						jobId,
						chunkNumber,
						compressedChunk,
						uncompressedSize
					);
					chunkNumber++;
					current.chunkNumber = chunkNumber;
					break;
				} catch (e) {
					if (uploadCancelledByUser || e.message === "aborted") {
						throw new Error("Upload cancelled by user");
					}

					console.warn("Chunk upload failed, will retry:", e);

					if (ui.uploadStatus) {
						ui.uploadStatus.textContent = `Upload interrupted — retrying in ${Math.round(
							attemptDelay / 1000
						)}s...`;
					}

					if (typeof navigator !== "undefined" && !navigator.onLine) {
						if (ui.uploadStatus)
							ui.uploadStatus.textContent =
								"Offline — waiting to reconnect...";
						await new Promise((resolve) => {
							const onOnline = () => {
								window.removeEventListener("online", onOnline);
								resolve();
							};
							window.addEventListener("online", onOnline);
						});
					} else {
						await new Promise((r) => setTimeout(r, attemptDelay));
						attemptDelay = Math.min(attemptDelay * 2, 60000);
					}
				}
			}
		}

		// Exit if file fully read and buffer empty
		if (fileOffset >= file.size && uncompressedBuffer.length === 0) {
			break;
		}
	}

	log(
		`Upload complete: ${formatBytes(decompressedTotal)} processed`,
		"upload"
	);
}

async function finalizeAndStart() {
	// CHANGED: uses module state
	if (ui.uploadPauseBtn) ui.uploadPauseBtn.style.display = "none";
	if (ui.uploadCancelBtn) ui.uploadCancelBtn.style.display = "none";

	if (ui.uploadStatus) ui.uploadStatus.textContent = "Finalizing upload...";

	const jobId = current.jobId;
	const file = current.file;
	if (!jobId || !file) throw new Error("Upload not initialized");

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

	if (ui.uploadStatus) {
		ui.uploadStatus.textContent = `Upload complete (${formatBytes(
			finalData.size
		)}) - Job ID: ${jobId}`;
	}
	if (ui.uploadProgress) ui.uploadProgress.value = 100;

	if (ui.importPhase) ui.importPhase.style.display = "block";

	const auto = el("opt_auto_start")?.checked || false;
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
}

export async function startUpload() {
	if (typeof window === "undefined") return;

	try {
		cacheUI();

		if (
			!ui.fileInput ||
			!ui.fileInput.files ||
			ui.fileInput.files.length === 0
		) {
			alert("No file selected");
			return;
		}

		// reset per-run state
		uploadCancelledByUser = false;
		isUploadPaused = false;
		currentChunkController = null;
		current.file = ui.fileInput.files[0];
		current.jobId = null;
		current.uploaded = 0;
		current.chunkNumber = 0;
		current.compressedBytesRead = 0;
		current.totalCompressedSize = 0;
		// keep current.chunkSize default unless server overrides

		if (uploadResolveResume) {
			uploadResolveResume(false);
			uploadResolveResume = null;
		}

		await validateFileAndCaps();

		const scope = val("import_scope") || "database";
		const scope_ident = val("import_scope_ident");

		setupUploadUI();

		await initOrResumeJob(scope, scope_ident);

		setupUploadButtons();

		await uploadAllChunks();

		if (current.fileKey) localStorage.removeItem(current.fileKey);
		await finalizeAndStart();
	} catch (error) {
		if (
			uploadCancelledByUser ||
			error.message === "aborted" ||
			error.message === "Upload cancelled by user"
		) {
			if (ui.uploadStatus)
				ui.uploadStatus.textContent = "Upload cancelled";
			else if (el("uploadStatus"))
				el("uploadStatus").textContent = "Upload cancelled";
		} else {
			alert("Upload error: " + error.message);
			console.error(error);
			if (ui.uploadStatus)
				ui.uploadStatus.textContent = "Upload failed: " + error.message;
			else if (el("uploadStatus"))
				el("uploadStatus").textContent =
					"Upload failed: " + error.message;
		}
	} finally {
		uploadCancelledByUser = false;
		currentChunkController = null;
	}
}
