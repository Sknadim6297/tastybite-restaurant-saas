document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.inventory-workspace-tabs[role="tablist"]').forEach((tabList) => {
        tabList.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                return;
            }

            const tabs = Array.from(tabList.querySelectorAll('[role="tab"]'));
            const currentIndex = tabs.indexOf(document.activeElement);
            if (currentIndex < 0 || tabs.length === 0) {
                return;
            }

            event.preventDefault();
            const nextIndex = event.key === 'Home'
                ? 0
                : event.key === 'End'
                    ? tabs.length - 1
                    : (currentIndex + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length;
            tabs[nextIndex].click();
        });
    });
});
