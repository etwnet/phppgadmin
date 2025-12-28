// Lightweight DOM utilities

// Shortcut for getElementById
export const el = (id) => document.getElementById(id);

// Query selector (single element)
export const qs = (sel, root = document) => root.querySelector(sel);

// Query selector (multiple elements, returned as array)
export const qsa = (sel, root = document) =>
	Array.from(root.querySelectorAll(sel));

// Event helper
export const on = (target, event, handler, opts) =>
	target.addEventListener(event, handler, opts);

// Value helper (safe input value getter)
export const val = (id) => el(id)?.value ?? "";

// Class helpers
export const addClass = (el, cls) => el.classList.add(cls);
export const removeClass = (el, cls) => el.classList.remove(cls);
export const toggleClass = (el, cls) => el.classList.toggle(cls);

// Visibility helpers
export const show = (el) => (el.style.display = "");
export const hide = (el) => (el.style.display = "none");

// Utility helpers for import module
const FNV_OFFSET_BASIS = BigInt("0xcbf29ce484222325");
const FNV_PRIME = BigInt("0x100000001b3");
const FNV_MASK = BigInt("0xffffffffffffffff");
const FNV_TABLE = Array.from({ length: 256 }, (_, i) => BigInt(i));

export const fnv1a64 = (buf) => {
	let hash = FNV_OFFSET_BASIS;
	for (let i = 0; i < buf.length; i++) {
		hash ^= FNV_TABLE[buf[i]];
		hash = (hash * FNV_PRIME) & FNV_MASK;
	}
	return hash.toString(16).padStart(16, "0");
};

export const formatBytes = (bytes) => {
	if (bytes === 0) return "0 B";
	const k = 1024;
	const sizes = ["B", "KB", "MB", "GB", "TB"];
	const i = Math.floor(Math.log(bytes) / Math.log(k));
	return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + " " + sizes[i];
};

export const detectZipSignature = (bytes) => {
	return (
		bytes.length >= 4 &&
		bytes[0] === 0x50 &&
		bytes[1] === 0x4b &&
		((bytes[2] === 0x03 && bytes[3] === 0x04) ||
			(bytes[2] === 0x05 && bytes[3] === 0x06) ||
			(bytes[2] === 0x07 && bytes[3] === 0x08))
	);
};

export const sniffMagicType = async (file) => {
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
};

export const getServerCaps = (fileInput) => {
	const ds = fileInput && fileInput.dataset ? fileInput.dataset : {};
	return {
		gzip: ds.capGzip === "1",
		zip: ds.capZip === "1",
		bzip2: ds.capBzip2 === "1",
	};
};

export const populateLog = (log) => {
	if (!Array.isArray(log)) {
		console.warn("Invalid log data", log);
		return;
	}
	const importLog = el("importLog");
	if (importLog) {
		importLog.textContent = log
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
};

export const log = (msg, type = "import") => {
	const p = el(type + "Log");
	if (!p) return;
	if (type === "upload") p.style.display = "block";
	const line = "[" + new Date().toISOString() + "] " + msg + "\n";
	p.textContent = line + p.textContent;
};

export const highlightActiveJob = (jobId) => {
	document.querySelectorAll(".import-job-row").forEach((row) => {
		if (row.dataset.jobId === jobId) {
			row.style.backgroundColor = "#e6f7ff";
			row.style.borderLeft = "4px solid #1890ff";
		} else {
			row.style.backgroundColor = "";
			row.style.borderLeft = "";
		}
	});
	const titleEl = el("importJobTitle");
	if (titleEl) titleEl.textContent = jobId || "Import Job";
};
