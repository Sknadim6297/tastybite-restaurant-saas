const productImageInput = document.querySelector("#product-image");
const productImagePreview = document.querySelector("[data-product-image-preview]");
const productImagePreviewContainer = document.querySelector("[data-product-image-preview-container]");
let selectedProductImageUrl = null;

if (productImageInput && productImagePreview && productImagePreviewContainer) {
    productImageInput.addEventListener("change", () => {
        if (selectedProductImageUrl) {
            URL.revokeObjectURL(selectedProductImageUrl);
            selectedProductImageUrl = null;
        }

        const [file] = productImageInput.files || [];
        if (!file) {
            return;
        }

        selectedProductImageUrl = URL.createObjectURL(file);
        productImagePreview.src = selectedProductImageUrl;
        productImagePreview.hidden = false;
        productImagePreviewContainer.hidden = false;
    });

    window.addEventListener("pagehide", () => {
        if (selectedProductImageUrl) {
            URL.revokeObjectURL(selectedProductImageUrl);
        }
    }, { once: true });
}
