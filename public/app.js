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
document.querySelectorAll("[data-confirm]").forEach((form) => {
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
