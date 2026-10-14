const brandingImageInput = document.querySelector("[data-branding-image-input]");
const brandingImagePreview = document.querySelector("[data-branding-image-preview]");
const brandingImageContainer = document.querySelector("[data-branding-image-container]");
let selectedBrandingImageUrl = null;

if (brandingImageInput && brandingImagePreview && brandingImageContainer) {
    const savedImageUrl = brandingImagePreview.src;
    const hasSavedImage = !brandingImagePreview.hidden;

    brandingImageInput.addEventListener("change", () => {
        if (selectedBrandingImageUrl) {
            URL.revokeObjectURL(selectedBrandingImageUrl);
            selectedBrandingImageUrl = null;
        }

        const [file] = brandingImageInput.files || [];
        if (!file) {
            brandingImagePreview.src = savedImageUrl;
            brandingImagePreview.hidden = !hasSavedImage;
            brandingImageContainer.hidden = !hasSavedImage;
            return;
        }

        selectedBrandingImageUrl = URL.createObjectURL(file);
        brandingImagePreview.src = selectedBrandingImageUrl;
        brandingImagePreview.hidden = false;
        brandingImageContainer.hidden = false;
    });

    window.addEventListener("pagehide", () => {
        if (selectedBrandingImageUrl) {
            URL.revokeObjectURL(selectedBrandingImageUrl);
        }
    }, { once: true });
}
