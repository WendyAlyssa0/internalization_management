
const form = document.getElementById("loginForm");
const loadingOverlay = document.getElementById("loadingOverlay");
const loginError = document.getElementById("loginError");
const button = form.querySelector(".login-btn");

const ARROW_SVG = '<svg class="icon-svg btn-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><polyline points="14 5 21 12 14 19"/></svg>';

function setButtonIdle() {
    button.disabled = false;
    button.innerHTML = '<span class="btn-label">Sign In</span>' + ARROW_SVG;
}

const toggleBtn = document.querySelector(".toggle-password");
const passwordInput = document.getElementById("passwordInput");

if (toggleBtn && passwordInput) {
    toggleBtn.addEventListener("click", function () {
        const showing = toggleBtn.classList.toggle("is-visible");
        passwordInput.type = showing ? "text" : "password";
        toggleBtn.setAttribute("aria-pressed", String(showing));
        toggleBtn.setAttribute(
            "aria-label",
            showing ? "Hide password" : "Show password"
        );
    });
}

form.addEventListener("submit", async function (e) {
    e.preventDefault();

    loginError.style.display = "none";
    loginError.textContent = "";

    button.disabled = true;
    button.innerHTML = '<span class="btn-label">Signing In...</span>';

    try {
        // Submit to the PHP login endpoint in the same folder.
        const response = await fetch("login.php", {
            method: "POST",
            body: new FormData(form),
            headers: {
                "Accept": "application/json",
                "X-Requested-With": "XMLHttpRequest"
            },
            credentials: "same-origin"
        });

        const text = await response.text();
        let data;

        try {
            data = JSON.parse(text);
        } catch {
            console.error("Non-JSON server response:", text);
            throw new Error(
                "The login server returned invalid JSON. Check login.php."
            );
        }

        if (!response.ok || !data.success) {
            throw new Error(data.error || "Login failed.");
        }

        if (!data.redirect) {
            throw new Error("The server did not provide a redirect URL.");
        }

        loadingOverlay.classList.add("show");
        window.location.assign(data.redirect);

    } catch (error) {
        console.error("Login error:", error);
        loginError.textContent =
            error.message || "Unable to contact the server.";
        loginError.style.display = "block";
        setButtonIdle();
    }
});
