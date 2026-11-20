"use strict";
document.querySelectorAll("[data-filter]").forEach((input) => {
  input.addEventListener("input", () => {
    const query = input.value.toLocaleLowerCase().trim();
    input.closest(".panel").querySelectorAll("[data-filter-row]").forEach((row) => {
      row.hidden = !row.textContent.toLocaleLowerCase().includes(query);
    });
  });
});
const upload = document.getElementById("files");
if (upload) {
  const update = () => {
    document.getElementById("selected-files").textContent =
      upload.files.length ? upload.files.length + " file(s) selected" : "No files selected";
  };
  upload.addEventListener("change", update);
  const zone = document.querySelector(".dropzone");
  zone.addEventListener("dragover", (event) => { event.preventDefault(); zone.classList.add("dragging"); });
  zone.addEventListener("dragleave", () => zone.classList.remove("dragging"));
  zone.addEventListener("drop", (event) => {
    event.preventDefault();
    zone.classList.remove("dragging");
    if (event.dataTransfer?.files.length) {
      upload.files = event.dataTransfer.files;
      update();
    }
  });
}
const entries = Array.from(document.querySelectorAll("[data-entry]"));
const count = document.getElementById("selected-count");
const refresh = () => {
  if (count) count.textContent = entries.filter((item) => item.checked).length + " selected";
};
entries.forEach((item) => item.addEventListener("change", refresh));
document.querySelectorAll("[data-select]").forEach((button) => {
  button.addEventListener("click", () => {
    entries.forEach((item) => {
      const action = button.dataset.select;
      item.checked = action === "all" || (action === "primary" && !["par2", "nfo", "sfv", "srr", "nzb"].includes(item.dataset.kind) && !item.closest("tr").textContent.toLowerCase().includes("sample"));
    });
    refresh();
  });
});
document.querySelectorAll("form[data-confirm]").forEach((form) => {
  form.addEventListener("submit", (event) => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  });
});
refresh();


/* One-click segment editing, with the same allowlisted choices as the server. */
const segmentEditor = document.getElementById("segment-editor");
if (segmentEditor) {
  const mode = document.getElementById("segment-operation");
  const number = document.getElementById("segment-number");
  const messageId = document.getElementById("segment-id");
  const bytes = document.getElementById("segment-bytes");
  let additions = [];
  let replacements = [];
  try {
    additions = JSON.parse(segmentEditor.dataset.addNumbers || "[]");
    replacements = JSON.parse(segmentEditor.dataset.replaceNumbers || "[]");
  } catch (_) {
    additions = [];
    replacements = [];
  }
  const showChoices = (preferred = null) => {
    const choices = mode.value === "segment_add" ? additions : replacements;
    number.replaceChildren();
    choices.forEach((n) => {
      const option = document.createElement("option");
      option.value = String(n);
      option.textContent = String(n);
      number.appendChild(option);
    });
    if (preferred !== null && choices.includes(Number(preferred))) number.value = String(preferred);
    number.disabled = choices.length === 0;
    number.required = choices.length !== 0;
  };
  mode.addEventListener("change", () => {
    showChoices();
    messageId.value = "";
    bytes.value = "";
  });
  document.querySelectorAll("[data-edit-segment]").forEach((link) => {
    link.addEventListener("click", (event) => {
      event.preventDefault();
      mode.value = "segment_replace";
      showChoices(Number(link.dataset.editSegment));
      messageId.value = link.dataset.segmentId || "";
      bytes.value = link.dataset.segmentBytes || "";
      segmentEditor.scrollIntoView({ behavior: "smooth", block: "center" });
      messageId.focus({ preventScroll: true });
    });
  });
}

/**
 * Local sorting for result tables returned by external search services.
 * Library, inspector and segment viewer use full-data server sorting instead.
 */
document.querySelectorAll("table[data-sortable]").forEach((table) => {
  const headerCells = Array.from(table.querySelectorAll("thead > tr:first-child > th"));
  const body = table.querySelector("tbody");
  if (!body) return;
  const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: "base" });
  const scalar = (text) => {
    const clean = text.trim().replace(/,/g, "");
    const byteMatch = clean.match(/^([\d.]+)\s*(B|KiB|MiB|GiB|TiB|KB|MB|GB|TB)$/i);
    if (byteMatch) {
      const unit = byteMatch[2].toUpperCase();
      const base = /IB$/.test(unit) ? 1024 : 1000;
      const power = { B: 0, KIB: 1, MIB: 2, GIB: 3, TIB: 4, KB: 1, MB: 2, GB: 3, TB: 4 }[unit];
      return Number(byteMatch[1]) * Math.pow(base, power);
    }
    const age = clean.match(/^([\d.]+)\s*(minute|min|hour|hr|day|week|month|year|m|h|d|w|mo|y)s?\b/i);
    if (age) {
      const unit = age[2].toLowerCase();
      const multiplier = { minute: 1, min: 1, m: 1, hour: 60, hr: 60, h: 60, day: 1440, d: 1440, week: 10080, w: 10080, month: 43200, mo: 43200, year: 525600, y: 525600 }[unit];
      return Number(age[1]) * multiplier;
    }
    if (/^-?\d+(?:\.\d+)?$/.test(clean)) return Number(clean);
    return null;
  };
  headerCells.forEach((th, index) => {
    if (th.dataset.noSort !== undefined || th.querySelector("a, button, input")) return;
    const button = document.createElement("button");
    button.type = "button";
    button.className = "sort-table-button";
    button.textContent = th.textContent.trim();
    th.replaceChildren(button);
    button.addEventListener("click", () => {
      const descending = button.getAttribute("aria-sort") === "ascending";
      table.querySelectorAll(".sort-table-button").forEach((other) => other.removeAttribute("aria-sort"));
      button.setAttribute("aria-sort", descending ? "descending" : "ascending");
      const rows = Array.from(body.querySelectorAll("tr"));
      rows.sort((a, b) => {
        const left = a.children[index];
        const right = b.children[index];
        const x = left?.dataset.sortValue ?? left?.textContent ?? "";
        const y = right?.dataset.sortValue ?? right?.textContent ?? "";
        const nx = scalar(x);
        const ny = scalar(y);
        const compare = nx !== null && ny !== null ? nx - ny : collator.compare(x.trim(), y.trim());
        return descending ? -compare : compare;
      });
      rows.forEach((row) => body.appendChild(row));
    });
  });
});

document.querySelectorAll("button[data-confirm]").forEach((button) => {
  button.addEventListener("click", (event) => {
    if (!window.confirm(button.dataset.confirm)) event.preventDefault();
  });
});

/* DSM folder picker: directory-only, server-enforced bounds and PHP permission checks. */
const folderDialog = document.getElementById("folder-picker");
if (folderDialog) {
  const list = folderDialog.querySelector("[data-folder-list]");
  const currentLabel = folderDialog.querySelector("[data-folder-current]");
  const up = folderDialog.querySelector("[data-folder-up]");
  const choose = folderDialog.querySelector("[data-folder-select]");
  const error = folderDialog.querySelector("[data-folder-error]");
  const permission = folderDialog.querySelector("[data-folder-permissions]");
  const token = document.querySelector("form.settings-form input[name='_csrf']")?.value || "";
  const fields = {
    workspace_path: document.getElementById("setting-workspace"),
    folder_import_path: document.getElementById("setting-import-folder"),
    push_folder_path: document.getElementById("setting-push-folder"),
    extract_output_path: document.getElementById("setting-extract-folder")
  };
  let target = null;
  let current = null;
  let sequence = 0;
  const row = (entry) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "folder-picker-entry";
    const name = document.createElement("strong");
    name.textContent = entry.name;
    const access = document.createElement("span");
    access.textContent = !entry.traversable || !entry.readable
      ? "No read/traverse permission"
      : (entry.writable ? "Read / Write" : "Read only");
    if (!entry.readable || !entry.traversable) access.className = "folder-picker-denied";
    button.append(name, access);
    button.addEventListener("click", () => browse(entry.path));
    return button;
  };
  const browse = async (path) => {
    const request = ++sequence;
    error.hidden = true;
    choose.disabled = true;
    up.disabled = true;
    up.dataset.fallbackParent = path.includes("/") ? path.slice(0, path.lastIndexOf("/")) : "";
    list.replaceChildren();
    currentLabel.textContent = path || "Available volumes";
    permission.textContent = "Checking access as the NZB Manager PHP service account…";
    try {
      const body = new URLSearchParams({ folder_browser: "1", path, field: target, _csrf: token });
      const response = await fetch("index.php", { method: "POST", body, credentials: "same-origin", cache: "no-store" });
      const data = await response.json();
      if (request !== sequence) return;
      if (!response.ok || data.error) throw new Error(data.error || "Directory list could not be loaded.");
      current = data;
      currentLabel.textContent = data.path || "Available volumes";
      up.disabled = data.parent === null;
      choose.disabled = !data.selectable;
      data.entries.forEach((entry) => list.appendChild(row(entry)));
      if (data.entries.length === 0) {
        const note = document.createElement("p");
        note.textContent = "No subfolders are visible to this PHP service account.";
        list.appendChild(note);
      }
      const flags = data.path
        ? (data.readable && data.traversable ? (data.writable ? "Read/write access" : "Read-only access") : "Permission denied")
        : "Select a volume, then a shared folder";
      const required = data.need === "write" ? "Write access is required for monitored-folder delivery." : "Read/traverse access is required.";
      permission.textContent = flags + " · " + required;
      if (data.path && !data.selectable && data.readable && data.traversable) {
        error.textContent = data.need === "write"
          ? "Permission denied: this folder is read-only for the NZB Manager PHP account."
          : "Select a shared folder inside an available volume.";
        error.hidden = false;
      }
    } catch (reason) {
      if (request !== sequence) return;
      current = null;
      up.disabled = path === "";
      error.textContent = reason instanceof Error ? reason.message : "Folder browsing failed.";
      error.hidden = false;
      permission.textContent = "Cannot verify folder permissions.";
    }
  };
  document.querySelectorAll("[data-folder-pick]").forEach((button) => {
    button.addEventListener("click", () => {
      target = button.dataset.folderPick;
      if (!fields[target]) return;
      folderDialog.showModal();
      browse(fields[target].value.trim() || "");
    });
  });
  up.addEventListener("click", () => {
    if (current && current.parent !== null && current.parent !== undefined) browse(current.parent);
    else if (!current && !up.disabled) browse(up.dataset.fallbackParent || "");
  });
  choose.addEventListener("click", () => {
    if (!current?.selectable || !fields[target]) return;
    fields[target].value = current.path;
    const label = document.querySelector("[data-permission-for='" + target + "']");
    if (label) {
      label.textContent = current.writable
        ? "Permission check passed: PHP account can read and write this folder."
        : "Permission check passed for browsing/import; this folder is read-only.";
      label.classList.remove("folder-permission-denied");
    }
    folderDialog.close();
  });
  folderDialog.querySelectorAll("[data-folder-close]").forEach((button) => {
    button.addEventListener("click", () => folderDialog.close());
  });
}


/** Append read-only verified PAR2 details after an asynchronous worker completes. */
const showPar2Verification = (cell, report) => {
  if (!report || typeof report !== "object" || cell.querySelector("[data-par2-verification]")) return;
  const details = document.createElement("details");
  details.dataset.par2Verification = "";
  details.className = "par2-verification-details";
  const summary = document.createElement("summary");
  summary.textContent = "View protected-file and recovery-volume checks";
  details.appendChild(summary);
  const meta = document.createElement("p");
  meta.textContent = "Recovery set " + (report.set || "") + " · " +
    Number(report.packet_count || 0) + " PAR2 packets validated";
  details.appendChild(meta);
  const summaryText = document.createElement("p");
  summaryText.textContent = Number(report.verified_files || 0) + "/" +
    Number(report.protected_files || 0) + " protected files verified; " +
    Number(report.checked_slices || 0) + "/" + Number(report.total_slices || 0) +
    " data slices checked; " + Number(report.recovery_volumes || 0) +
    " recovery volumes, " + Number(report.recovery_slices || 0) + " recovery slices validated";
  details.appendChild(summaryText);
  const addGroup = (heading, items, emptyText, render) => {
    const label = document.createElement("strong");
    label.textContent = heading;
    details.appendChild(label);
    const list = document.createElement("ul");
    if (Array.isArray(items) && items.length > 0) {
      items.forEach((item) => {
        const line = document.createElement("li");
        line.textContent = render(item);
        list.appendChild(line);
      });
    } else {
      const line = document.createElement("li");
      line.textContent = emptyText;
      list.appendChild(line);
    }
    details.appendChild(list);
  };
  addGroup("Protected files", report.files, "No protected-file details available", (file) =>
    String(file.name || "") + ": " + String(file.status || "unknown") +
    " (" + Number(file.checked_slices || 0) + "/" + Number(file.total_slices || 0) +
    " slices checked" + (file.bad_slice_count ? ", " + Number(file.bad_slice_count) + " damaged; slice indices: " +
      (Array.isArray(file.bad_slices) ? file.bad_slices.slice(0, 20).join(", ") : "") : "") + ")");
  addGroup("Recovery volumes (validated)", report.volume_details, "No recovery slices available", (volume) =>
    String(volume.name || "") + ": " + Number(volume.slices || 0) + " recovery slice(s)");
  cell.appendChild(details);
};

/** Read-only PHP background task progress. JSON is served from the same origin. */
document.querySelectorAll("[data-background-monitor]").forEach((monitor) => {
  const category = monitor.dataset.backgroundMonitor;
  let busy = false;
  const poll = async () => {
    if (busy || document.visibilityState === "hidden") return;
    busy = true;
    try {
      const query = new URLSearchParams({ job_status: "1", type: category });
      const response = await fetch("index.php?" + query.toString(), { cache: "no-store", credentials: "same-origin" });
      if (!response.ok) throw new Error("Background status HTTP " + response.status);
      const info = await response.json();
      for (const job of info.jobs || []) {
        if (!/^[a-f0-9]{32}$/.test(job.id || "")) continue;
        const row = monitor.querySelector('[data-job-row="' + job.id + '"]');
        if (!row) continue;
        const progress = Math.max(0, Math.min(100, Number(job.progress) || 0));
        const bar = row.querySelector("[data-job-progress]");
        const percent = row.querySelector("[data-job-percent]");
        const state = row.querySelector("[data-job-state]");
        const message = row.querySelector("[data-job-message]");
        // Reload a single time after a running extraction completes, so newly
        // extracted folders appear in the file list without manual Refresh.
        const previousJobStatus = row.dataset.jobStatus || "";
        row.dataset.jobStatus = job.status || "";
        if (category === "archive_extract" &&
            (previousJobStatus === "queued" || previousJobStatus === "running") &&
            job.status === "completed") {
          window.location.reload();
          return;
        }
        if (bar) bar.value = progress;
        if (percent) percent.textContent = progress + "%";
        if (state) {
          const isPar2 = category === "par2";
          const hasVerification = isPar2 && job.type === "par2_verify" &&
            job.verification && typeof job.verification === "object";
          const damaged = hasVerification && Number(job.verification.damaged_files || 0) > 0;
          const displayState = damaged ? "Failed — Repair required" :
            (hasVerification && job.status === "completed" ? "Verified — No repair needed" :
            (isPar2 && job.type === "par2_verify" && job.status === "completed" ? "Old scan — Rerun to verify" :
            (isPar2 && job.type === "par2_repair" && job.status === "completed" ? "Repaired" :
            (job.status || "unknown"))));
          state.textContent = displayState;
          state.classList.toggle("par2-state-damaged", Boolean(damaged || (isPar2 && job.status === "failed")));
        }
        if (message) {
          let summary = message.querySelector("[data-job-summary]");
          if (!summary) {
            message.textContent = "";
            summary = document.createElement("span");
            summary.dataset.jobSummary = "";
            message.appendChild(summary);
          }
          summary.textContent = job.message || "";
          if (category === "par2") showPar2Verification(message, job.verification);
        }
        if (["completed", "failed", "cancelled"].includes(job.status)) {
          const cancelForm = row.querySelector('form:has(input[value$="_cancel"])');
          if (cancelForm) {
            const token = cancelForm.querySelector('input[name="_csrf"]')?.value || "";
            const cell = cancelForm.parentElement;
            cancelForm.remove();
            if (token && cell) {
              const actions = document.createElement("div");
              actions.className = "job-actions";
              const prefix = category === "par2" ? "par2" : "archive";
              const endpoint = category === "par2" ? "repair" : "unpack";
              const actionForm = (suffix, title, confirmText, className) => {
                const form = document.createElement("form");
                form.method = "post";
                const folder = new URLSearchParams(window.location.search).get("folder") || "";
                form.action = "index.php?module=" + endpoint +
                  (folder ? "&folder=" + encodeURIComponent(folder) : "");
                form.dataset.confirm = confirmText;
                for (const [name, value] of Object.entries({ _csrf: token, action: prefix + "_" + suffix, job_id: job.id })) {
                  const input = document.createElement("input");
                  input.type = "hidden";
                  input.name = name;
                  input.value = value;
                  form.appendChild(input);
                }
                if (category === "archive_extract" && suffix === "rerun" && job.needs_password) {
                  const password = document.createElement("input");
                  password.type = "password";
                  password.name = "password";
                  password.className = "archive-job-password";
                  password.autocomplete = "new-password";
                  password.maxLength = 1024;
                  password.placeholder = "Enter ZIP password";
                  password.required = true;
                  password.setAttribute("aria-label", "ZIP password for rerun");
                  form.appendChild(password);
                }
                const button = document.createElement("button");
                button.type = "submit";
                button.className = "button " + className;
                button.textContent = title;
                form.appendChild(button);
                form.addEventListener("submit", (event) => {
                  if (!window.confirm(form.dataset.confirm)) event.preventDefault();
                });
                return form;
              };
              if (category === "par2" && job.type === "par2_verify" &&
                  job.status === "failed" && Number(job.verification?.damaged_files || 0) > 0) {
                actions.append(actionForm("repair_from_verify", "Repair",
                  "Start native PHP PAR2 Repair? The worker rechecks every protected slice and does not rename files that now verify.",
                  "primary"));
              }
              actions.append(
                actionForm("rerun", category === "archive_extract" && job.needs_password ? "Retry with password" : "Rerun",
                  "Rerun this job? Existing output files will be preserved.", "secondary"),
                actionForm("forget", "Remove", "Remove this finished job from history? Output files will be preserved.", "subtle")
              );
              cell.appendChild(actions);
            }
          }
        }
      }
    } catch (error) {
      monitor.dataset.monitorError = error instanceof Error ? error.message : "Status unavailable";
    } finally {
      busy = false;
    }
  };
  void poll();
  setInterval(poll, 1300);
});
