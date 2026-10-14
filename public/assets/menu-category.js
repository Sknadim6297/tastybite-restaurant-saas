const iconSearch = document.querySelector("[data-category-icon-search]");
const iconValue = document.querySelector("[data-category-icon-value]");
const iconChoices = document.querySelectorAll("[data-category-icon-choice]");
const iconPreview = document.querySelector("[data-category-icon-preview] i");

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
            iconPreview.className = `bi bi-${iconValue.value || "grid"}`;

            iconChoices.forEach((option) => {
                option.setAttribute("aria-pressed", option === choice ? "true" : "false");
            });
        });
    });
}

const categoryImageInput = document.querySelector("[data-category-image-input]");
const categoryImagePreview = document.querySelector("[data-category-image-preview]");
const categoryImagePreviewContainer = document.querySelector("[data-category-image-preview-container]");
let selectedCategoryImageUrl = null;

if (categoryImageInput && categoryImagePreview && categoryImagePreviewContainer) {
    const savedImageUrl = categoryImagePreview.src;
    const hasSavedImage = !categoryImagePreview.hidden;

    categoryImageInput.addEventListener("change", () => {
        if (selectedCategoryImageUrl) {
            URL.revokeObjectURL(selectedCategoryImageUrl);
            selectedCategoryImageUrl = null;
        }

        const [file] = categoryImageInput.files || [];
        if (!file) {
            categoryImagePreview.src = savedImageUrl;
            categoryImagePreview.hidden = !hasSavedImage;
            categoryImagePreviewContainer.hidden = !hasSavedImage;
            return;
        }

        selectedCategoryImageUrl = URL.createObjectURL(file);
        categoryImagePreview.src = selectedCategoryImageUrl;
        categoryImagePreview.hidden = false;
        categoryImagePreviewContainer.hidden = false;
    });

    window.addEventListener("pagehide", () => {
        if (selectedCategoryImageUrl) {
            URL.revokeObjectURL(selectedCategoryImageUrl);
        }
    }, { once: true });
}
