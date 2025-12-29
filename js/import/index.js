import { startUpload } from "./uploader.js";
import { runImportLoop, showJobStatus } from "./importer.js";
import { refreshEmbeddedJobList } from "./jobs.js";
import { appendServerToParams, appendServerToUrl } from "./api.js";
import { el } from "./utils.js";

export function init() {
	const btn = el("importStart");
	if (btn) btn.addEventListener("click", startUpload);

	refreshEmbeddedJobList();

	const showAllChk = el("opt_show_all");
	if (showAllChk)
		showAllChk.addEventListener("change", refreshEmbeddedJobList);

	const entryImportBtn = el("entryImportBtn");
	const entryCancelBtn = el("entryCancelBtn");
	if (entryImportBtn) {
		entryImportBtn.addEventListener("click", function () {
			const modal = el("entrySelectorModal");
			if (!modal) return;
			const jobId = modal.dataset.jobId;
			const totalSize = modal.dataset.totalSize || 0;
			const sel = el("entrySelect");
			const importAll = el("import_all_chk")?.checked;
			const params = new URLSearchParams();
			params.append("job_id", jobId);
			if (importAll) params.append("import_all", "1");
			else params.append("entry", sel.value);
			appendServerToParams(params);
			(async () => {
				try {
					const resp = await fetch(
						appendServerToUrl("dbimport.php?action=select_entry"),
						{ method: "POST", body: params }
					);
					if (!resp.ok) throw new Error("select_entry failed");
					await resp.json();
					modal.style.display = "none";
					runImportLoop(jobId, totalSize);
				} catch (e) {
					alert("Failed to select entry: " + e);
				}
			})();
		});
	}
	if (entryCancelBtn)
		entryCancelBtn.addEventListener("click", function () {
			el("entrySelectorModal").style.display = "none";
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
