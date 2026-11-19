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
