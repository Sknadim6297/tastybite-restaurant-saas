const iconSearch = document.querySelector("[data-category-icon-search]");
const iconValue = document.querySelector("[data-category-icon-value]");
const iconChoices = document.querySelectorAll("[data-category-icon-choice]");
const iconPreview = document.querySelector("[data-category-icon-preview] i");
const iconPreviewLabel = document.querySelector("[data-category-icon-preview-label]");

if (iconSearch && iconChoices.length) {
    iconSearch.addEventListener("input", () => {
        const search = iconSearch.value.trim().toLowerCase();

        iconChoices.forEach((choice) => {
            choice.hidden = !choice.dataset.categoryIconLabel.includes(search);
        });
    });
}

if (iconValue && iconPreview) {
    iconChoices.forEach((choice) => {
        choice.addEventListener("click", () => {
            iconValue.value = choice.dataset.categoryIconChoice;
            iconPreview.className = `bi bi-${choice.dataset.iconClass || "grid"}`;
            if (iconPreviewLabel) {
                iconPreviewLabel.textContent = `Selected: ${choice.querySelector("span")?.textContent || "Default"}`;
            }

            iconChoices.forEach((option) => {
                option.setAttribute("aria-pressed", option === choice ? "true" : "false");
            });
        });
    });
}
