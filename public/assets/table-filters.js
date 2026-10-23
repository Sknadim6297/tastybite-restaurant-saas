(() => {
    "use strict";

    document.querySelectorAll("[data-floor-dependent-section]").forEach(form => {
        const floorSelect = form.querySelector("[data-floor-select]");
        const sectionSelect = form.querySelector("[data-section-select]");
        if (!floorSelect || !sectionSelect) return;

        const syncSections = () => {
            const selectedFloor = floorSelect.value;
            const currentValue = sectionSelect.value;
            const noSectionOption = document.createElement("option");
            noSectionOption.value = "";
            noSectionOption.textContent = "No section";

            const filteredOptions = [...sectionSelect.querySelectorAll("option")].filter(option => {
                const optionFloor = option.dataset.floorId ?? "";
                return option.value === "" || !selectedFloor || optionFloor === selectedFloor;
            });

            sectionSelect.innerHTML = "";
            sectionSelect.appendChild(noSectionOption);
            filteredOptions.filter(option => option.value !== "").forEach(option => {
                const clone = document.createElement("option");
                clone.value = option.value;
                clone.textContent = option.textContent;
                clone.dataset.floorId = option.dataset.floorId ?? "";
                sectionSelect.appendChild(clone);
            });

            if (filteredOptions.some(option => option.value === currentValue)) {
                sectionSelect.value = currentValue;
            } else {
                sectionSelect.value = "";
            }
        };

        floorSelect.addEventListener("change", syncSections);
        syncSections();
    });

    document.querySelectorAll("[data-table-filter]").forEach(form => {
        let timer;
        let navigating = false;
        const loading = form.querySelector(".table-loading");
        const submit = () => {
            if (navigating) return;
            navigating = true;
            if (loading) loading.hidden = false;
            if (typeof form.requestSubmit === "function") form.requestSubmit();
            else form.submit();
        };

        form.querySelectorAll("[data-auto-submit]").forEach(control => {
            control.addEventListener("change", submit);
        });
        form.querySelectorAll("[data-debounce]").forEach(control => {
            control.addEventListener("input", () => {
                window.clearTimeout(timer);
                timer = window.setTimeout(submit, 300);
            });
        });
        form.addEventListener("submit", () => {
            if (loading) loading.hidden = false;
        });
    });

    const liveGrid = document.getElementById("table-overview-live");
    if (liveGrid?.dataset.feedUrl) {
        const checkStatus = async () => {
            if (document.hidden) return;
            try {
                const response = await fetch(liveGrid.dataset.feedUrl, {
                    credentials: "same-origin",
                    headers: { Accept: "application/json" },
                    cache: "no-store"
                });
                if (!response.ok) return;
                const payload = await response.json();
                const cards = [...liveGrid.querySelectorAll("[data-table-card]")];
                const remote = payload.tables || [];
                if (cards.length !== remote.length || remote.some(table => {
                    const card = cards.find(item => item.dataset.tableCard === String(table.id));
                    return !card || card.dataset.status !== table.status;
                })) {
                    window.location.reload();
                }
            } catch (_) {
                // Keep the current view available when a transient poll fails.
            }
        };
        window.setInterval(checkStatus, 30000);
    }

})();
