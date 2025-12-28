import { startUpload } from "./uploader.js";
import { runImportLoop, showJobStatus } from "./importer.js";
import { refreshEmbeddedJobList } from "./jobs.js";
import { appendServerToParams, appendServerToUrl } from "./api.js";

export function init() {
	const btn = document.getElementById("importStart");
	if (btn) btn.addEventListener("click", startUpload);

	refreshEmbeddedJobList();

	const showAllChk = document.getElementById("opt_show_all");
	if (showAllChk)
		showAllChk.addEventListener("change", refreshEmbeddedJobList);

	const entryImportBtn = document.getElementById("entryImportBtn");
	const entryCancelBtn = document.getElementById("entryCancelBtn");
	if (entryImportBtn) {
		entryImportBtn.addEventListener("click", function () {
			const modal = document.getElementById("entrySelectorModal");
			if (!modal) return;
			const jobId = modal.dataset.jobId;
			const totalSize = modal.dataset.totalSize || 0;
			const sel = document.getElementById("entrySelect");
			const importAll = document.getElementById("import_all_chk").checked;
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
	if (entryCancelBtn)
		entryCancelBtn.addEventListener("click", function () {
			document.getElementById("entrySelectorModal").style.display =
				"none";
		});

	// Listen for job actions dispatched by jobs.js
	document.addEventListener("import:view", (e) => {
		const d = e.detail || {};
		showJobStatus(d.jobId, d.size);
	});
	document.addEventListener("import:start", (e) => {
		const d = e.detail || {};
		runImportLoop(d.jobId, d.size);
	});
}

export { refreshEmbeddedJobList };
