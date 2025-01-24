class RedirectHelper {
    saveRedirectPath() {
        const currentUrl = new URL(window.location.href);
        const params = new URLSearchParams(currentUrl.search);
        const redirectPath = params.get('redirect');

        if (!redirectPath) {
            return;
        }

        localStorage.setItem('redirect', redirectPath);
        this.checkRedirectPath();
    }

    getRedirectPath() {
        this.checkRedirectPath();
        return localStorage.getItem('redirect') || null;
    }

    clearRedirectPath() {
        localStorage.removeItem('redirect');
    }

    checkRedirectPath() {
        let path = localStorage.getItem('redirect');

        if (!path) {
            return null;
        }

        const currentUrl = new URL(window.location.href);
        const redirectUrl = new URL(path, currentUrl.origin);

        if (
            redirectUrl.origin === currentUrl.origin
            && redirectUrl.pathname
            && redirectUrl.pathname !== '/'
            && redirectUrl.pathname !== currentUrl.pathname
        ) {
            localStorage.setItem('redirect', redirectUrl.pathname);
            return;
        }

        localStorage.removeItem('redirect');
    }
}

let helper = new RedirectHelper();
export default helper;