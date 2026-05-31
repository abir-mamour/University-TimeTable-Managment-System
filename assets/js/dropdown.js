// ─── Topbar User Dropdown ─────────────────────

function toggleDropdown() {
    const dropdown = document.getElementById('userDropdown');
    const chevron  = document.getElementById('dropdownChevron');
    dropdown.classList.toggle('open');
    chevron.classList.toggle('rotated');
}

// ─── Close when clicking outside ─────────────
document.addEventListener('click', function(e) {
    const wrapper = document.querySelector('.user-dropdown-wrapper');
    if (wrapper && !wrapper.contains(e.target)) {
        document.getElementById('userDropdown').classList.remove('open');
        document.getElementById('dropdownChevron').classList.remove('rotated');
    }
});