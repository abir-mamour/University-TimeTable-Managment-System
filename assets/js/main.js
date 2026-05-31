document.addEventListener('DOMContentLoaded', () => {

    const form       = document.getElementById('loginForm');
    const regInput   = document.getElementById('reg_number');
    const passInput  = document.getElementById('password');
    const toggleBtn  = document.getElementById('togglePassword');
    const eyeIcon    = document.getElementById('eyeIcon');
    const loginBtn   = document.getElementById('loginBtn');
    const btnText    = document.getElementById('btnText');
    const btnLoader  = document.getElementById('btnLoader');
    const errorAlert = document.getElementById('errorAlert');
    const errorText  = document.getElementById('errorText');
    const regError   = document.getElementById('regError');
    const passError  = document.getElementById('passError');

    // ─── Toggle Password ──────────────────────
    toggleBtn.addEventListener('click', () => {
        const isHidden = passInput.type === 'password';
        passInput.type = isHidden ? 'text' : 'password';
        eyeIcon.className = isHidden
            ? 'fa-regular fa-eye-slash'
            : 'fa-regular fa-eye';
    });

    // ─── Clear errors on typing ───────────────
    regInput.addEventListener('input',  () => clearFieldError('reg'));
    passInput.addEventListener('input', () => clearFieldError('pass'));

    // ─── Form Submit ──────────────────────────
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        if (!validate()) return;

        setLoading(true);
        hideAlert();

        // ── Direct fetch (no api.js needed) ───
        try {
            const res = await fetch(
                '/TimeTable/api/auth/login.php',
                {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        reg_number: regInput.value.trim(),
                        password:   passInput.value,
                    })
                }
            );

            const text = await res.text();

            let result;
            try {
                result = JSON.parse(text);
            } catch(parseErr) {
                setLoading(false);
                showAlert('Server error: ' + text.substring(0, 200));
                return;
            }

            setLoading(false);

            if (result.success) {
                window.location.href = result.redirect;
            } else {
                showAlert(result.message || 'Invalid credentials.');
            }

        } catch (networkErr) {
            setLoading(false);
            showAlert('Network error: ' + networkErr.message);
            console.error('Fetch failed:', networkErr);
        }
    });

    // ─── Validation ───────────────────────────
    function validate() {
        let valid = true;

        if (!regInput.value.trim()) {
            showFieldError('reg', 'Registration number is required.');
            valid = false;
        }

        if (!passInput.value) {
            showFieldError('pass', 'Password is required.');
            valid = false;
        }

        return valid;
    }

    // ─── Helpers ──────────────────────────────
    function setLoading(state) {
        loginBtn.disabled       = state;
        btnText.style.display   = state ? 'none'        : 'inline';
        btnLoader.style.display = state ? 'inline-flex' : 'none';
    }

    function showAlert(msg) {
        errorText.textContent    = msg;
        errorAlert.style.display = 'flex';
    }

    function hideAlert() {
        errorAlert.style.display = 'none';
    }

    function showFieldError(field, msg) {
        if (field === 'reg') {
            regError.textContent = msg;
            regInput.classList.add('input-error');
        } else {
            passError.textContent = msg;
            passInput.classList.add('input-error');
        }
    }

    function clearFieldError(field) {
        if (field === 'reg') {
            regError.textContent = '';
            regInput.classList.remove('input-error');
        } else {
            passError.textContent = '';
            passInput.classList.remove('input-error');
        }
        hideAlert();
    }
});