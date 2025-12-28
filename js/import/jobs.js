import { el, log } from "./utils.js";
import { appendServerToUrl } from "./api.js";

export function createJobRowFromTemplate(j, updater) {
	let tpl = document.getElementById("job-row-template");
	const frag = tpl.content.cloneNode(true);
	const row = frag.querySelector(".import-job-row");
	if (row) row.dataset.jobId = j.job_id;

	frag.querySelector(".job-info").textContent = `${j.job_id} — ${
		j.status
	} — ${j.offset || 0}/${j.size || 0}`;

	const viewBtn = frag.querySelector(".view");
	const startBtn = frag.querySelector(".start");
	const cancelBtn = frag.querySelector(".cancel");
	const resumeBtn = frag.querySelector(".resume");
	const deleteBtn = frag.querySelector(".delete");

	if (j.status === "uploading") {
		if (startBtn) startBtn.style.display = "none";
		if (resumeBtn) resumeBtn.style.display = "none";
		if (viewBtn) viewBtn.textContent = "View Upload";
		if (deleteBtn) deleteBtn.style.display = "inline-block";
	} else if (j.status === "uploaded" || j.status === "running") {
		if (startBtn) {
			startBtn.style.display = "inline-block";
			startBtn.textContent =
				j.status === "running" ? "Continue" : "Start Import";
		}
		if (resumeBtn) resumeBtn.style.display = "none";
		if (j.status === "running") {
			if (deleteBtn) deleteBtn.style.display = "none";
		}
	} else if (j.status === "cancelled" || j.status === "error") {
		if (startBtn) startBtn.style.display = "none";
		if (resumeBtn) {
			resumeBtn.style.display = "inline-block";
			resumeBtn.textContent = "Retry / Resume";
		}
		if (deleteBtn) deleteBtn.style.display = "inline-block";
	} else if (j.status === "finished") {
		if (startBtn) startBtn.style.display = "none";
		if (cancelBtn) cancelBtn.style.display = "none";
		if (resumeBtn) resumeBtn.style.display = "none";
		if (viewBtn) viewBtn.textContent = "View Log";
		if (deleteBtn) deleteBtn.style.display = "inline-block";
	}

	if (viewBtn)
		viewBtn.addEventListener("click", () =>
			document.dispatchEvent(
				new CustomEvent("import:view", {
					detail: { jobId: j.job_id, size: j.size },
				})
			)
		);
	if (startBtn)
		startBtn.addEventListener("click", () =>
			document.dispatchEvent(
				new CustomEvent("import:start", {
					detail: { jobId: j.job_id, size: j.size },
				})
			)
		);
	if (cancelBtn)
		cancelBtn.addEventListener("click", () => {
			if (!confirm("Are you sure you want to cancel this job?")) return;
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
				document.dispatchEvent(
					new CustomEvent("import:start", {
						detail: { jobId: j.job_id, size: j.size },
					})
				);
			});
		});
	if (deleteBtn)
		deleteBtn.addEventListener("click", () => {
			if (
				!confirm(
					"Are you sure you want to delete this job? This cannot be undone."
				)
			)
				return;
			fetch(
				appendServerToUrl(
					"dbimport.php?action=delete_job&job_id=" +
						encodeURIComponent(j.job_id)
				),
				{ method: "POST" }
			)
				.then((r) => r.json())
				.then((res) => {
					if (res.error) {
						alert("Delete failed: " + res.error);
					} else {
						if (typeof updater === "function") updater();
					}
				})
				.catch((e) => alert("Delete failed: " + e));
		});

	return frag;
}

export function refreshEmbeddedJobList() {
	const container = document.getElementById("uploadedJobsList");
	if (!container) return;
	container.textContent = "Loading...";
	const showAllParam2 =
		document.getElementById("opt_show_all") &&
		document.getElementById("opt_show_all").checked
			? "&show_all=1"
			: "";
	fetch(appendServerToUrl("dbimport.php?action=list_jobs" + showAllParam2))
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
