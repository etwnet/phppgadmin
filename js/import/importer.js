import { el, log, populateLog, formatBytes } from "./utils.js";
import { appendServerToUrl } from "./api.js";
import { refreshEmbeddedJobList } from "./jobs.js";

let activeImportJobId = null;

export const highlightActiveJob = (jobId) => {
	document.querySelectorAll(".import-job-row").forEach((row) => {
		if (row.dataset.jobId === jobId) {
			row.classList.add("active");
		} else {
			row.classList.remove("active");
		}
	});
	const titleEl = el("importJobTitle");
	if (titleEl) titleEl.textContent = jobId || "Import Job";
};

export const showJobStatus = async (jobId, totalSize) => {
	if (activeImportJobId && activeImportJobId !== jobId) {
		alert("Cannot view other jobs while an import is running.");
		return;
	}

	try {
		highlightActiveJob(jobId);

		const statusResp = await fetch(
			appendServerToUrl(
				"dbimport.php?action=status&job_id=" + encodeURIComponent(jobId)
			)
		);
		if (!statusResp.ok) {
			alert("Failed to load job status");
			return;
		}
		const data = await statusResp.json();
		const importUI = el("importUI");
		if (importUI) importUI.style.display = "block";
		const uploadPhase = el("uploadPhase");
		const importPhase = el("importPhase");
		if (uploadPhase) uploadPhase.style.display = "none";
		if (importPhase) importPhase.style.display = "block";

		if (data.offset && totalSize) {
			const pct = Math.floor((data.offset / totalSize) * 100);
			if (el("importProgress")) el("importProgress").value = pct;
		}
		const importStatus = el("importStatus");
		if (importStatus)
			importStatus.textContent = `${data.status || ""} - Errors: ${
				data.errors || 0
			}`;

		populateLog(data.log);
	} catch (e) {
		console.error(e);
		alert("Failed to fetch job status");
	}
};

export const runImportLoop = async (jobId, totalSize) => {
	if (activeImportJobId === jobId) return;
	if (activeImportJobId && activeImportJobId !== jobId) {
		alert(
			"Another import is currently running. Please wait for it to finish."
		);
		return;
	}
	if (typeof window === "undefined") return;
	activeImportJobId = jobId;
	highlightActiveJob(jobId);

	try {
		let running = true;

		try {
			const statusResp = await fetch(
				appendServerToUrl(
					"dbimport.php?action=status&job_id=" +
						encodeURIComponent(jobId)
				)
			);
			if (statusResp.ok) {
				const sdata = await statusResp.json();
				if (sdata.status === "cancelled") {
					try {
						const resumeResp = await fetch(
							appendServerToUrl(
								"dbimport.php?action=resume_job&job_id=" +
									encodeURIComponent(jobId)
							)
						);
						if (resumeResp.ok) {
							try {
								refreshEmbeddedJobList();
							} catch (e) {}
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
					} catch (e) {}
				}
				populateLog(sdata.log);
				if (sdata.offset && totalSize) {
					const pct = Math.floor((sdata.offset / totalSize) * 100);
					if (el("importProgress")) el("importProgress").value = pct;
				}
			}
		} catch (e) {}

		try {
			refreshEmbeddedJobList();
		} catch (e) {}

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
						if (data.current_db)
							statusText += ` - DB: ${data.current_db}`;
						if (data.errors)
							statusText += ` - Errors: ${data.errors}`;
						importStatus.textContent = statusText;
					}
					log(
						`Processed ${formatBytes(data.offset)} / ${formatBytes(
							totalSize
						)} (${pct}%)`
					);
				}
				populateLog(data.log);

				if (
					data.status === "finished" ||
					data.status === "error" ||
					(totalSize && data.offset >= totalSize)
				) {
					const importStatus = el("importStatus");
					if (importStatus)
						importStatus.textContent = `Import complete - Errors: ${
							data.errors || 0
						}`;
					log(
						"Import finished for job " +
							jobId +
							" with errors: " +
							(data.errors || 0)
					);
					return false;
				}
				return true;
			} catch (e) {
				log("Process error: " + e);
				return false;
			}
		}

		while (running) {
			const cont = await step();
			if (!cont) break;
			await new Promise((r) => setTimeout(r, 700));
		}

		try {
			refreshEmbeddedJobList();
		} catch (e) {}
	} finally {
		activeImportJobId = null;
	}
};
