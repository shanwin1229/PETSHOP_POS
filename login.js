const SESSION_KEY = 'pawpos_session';
const REMEMBER_KEY = 'pawpos_remember';

function normalizeRole(role) {
  const value = String(role || '').trim().toLowerCase();
  if (value === 'admin' || value === 'administrator') return 'Admin';
  if (value === 'cashier') return 'Cashier';
  if (value === 'groomer') return 'Groomer';
  return String(role || '');
}

function getRedirectForRole(role) {
  const cleanRole = normalizeRole(role);
  if (cleanRole === 'Admin') return 'dashboard.html';
  if (cleanRole === 'Groomer') return 'appointments.html';
  return 'pos.html';
}

const usernameInput = document.getElementById('username');
const passwordInput = document.getElementById('password');
const rememberCheck = document.getElementById('rememberMe');
const loginBtn = document.getElementById('loginBtn');
const loginIcon = document.getElementById('loginIcon');
const loginText = document.getElementById('loginText');
const errorBox = document.getElementById('errorBox');
const errorMsg = document.getElementById('errorMsg');
const eyeBtn = document.getElementById('eyeBtn');
const eyeIcon = document.getElementById('eyeIcon');
const forgotLink = document.getElementById('forgotLink');
const forgotModal = document.getElementById('forgotModal');
const cancelReset = document.getElementById('cancelReset');
const confirmReset = document.getElementById('confirmReset');
const resetEmailInput = document.getElementById('resetEmail');

function getSession() {
  try {
    const raw = sessionStorage.getItem(SESSION_KEY) || localStorage.getItem(SESSION_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

function isSessionValid(session) {
  if (!session || !session.userId || !session.loginTime) return false;
  return Date.now() - session.loginTime < 8 * 60 * 60 * 1000;
}

function saveSession(user, remember) {
  const session = {
    userId: user.id,
    username: user.username,
    name: user.name || user.username,
    role: normalizeRole(user.role),
    loginTime: Date.now()
  };

  sessionStorage.removeItem(SESSION_KEY);
  localStorage.removeItem(SESSION_KEY);

  const storage = remember ? localStorage : sessionStorage;
  storage.setItem(SESSION_KEY, JSON.stringify(session));

  if (remember) {
    localStorage.setItem(REMEMBER_KEY, 'true');
  } else {
    localStorage.removeItem(REMEMBER_KEY);
  }
}

function clearSession() {
  sessionStorage.removeItem(SESSION_KEY);
  localStorage.removeItem(SESSION_KEY);
  localStorage.removeItem(REMEMBER_KEY);
}

function showError(message) {
  if (!errorBox || !errorMsg) {
    alert(message);
    return;
  }
  errorMsg.textContent = message;
  errorBox.classList.add('show');
  errorBox.setAttribute('aria-hidden', 'false');
}

function hideError() {
  if (!errorBox) return;
  errorBox.classList.remove('show');
  errorBox.setAttribute('aria-hidden', 'true');
}

function setLoading(loading) {
  if (!loginBtn) return;
  loginBtn.disabled = loading;

  if (loginIcon) loginIcon.className = loading ? 'ti ti-loader spin' : 'ti ti-login';
  if (loginText) loginText.textContent = loading ? 'Signing in...' : 'Sign in';
}

function validateInputs() {
  const username = usernameInput ? usernameInput.value.trim() : '';
  const password = passwordInput ? passwordInput.value : '';

  if (!username && !password) {
    showError('Please enter your username and password.');
    usernameInput && usernameInput.focus();
    return false;
  }

  if (!username) {
    showError('Please enter your username.');
    usernameInput && usernameInput.focus();
    return false;
  }

  if (!password) {
    showError('Please enter your password.');
    passwordInput && passwordInput.focus();
    return false;
  }

  if (password.length < 6) {
    showError('Password must be at least 6 characters.');
    passwordInput && passwordInput.focus();
    return false;
  }

  return true;
}

async function authenticate(username, password, remember) {
  const response = await fetch('login.php?action=login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ username, password, remember })
  });

  const payload = await response.json().catch(() => null);

  if (!response.ok || !payload || !payload.success) {
    throw new Error(payload?.message || 'Incorrect username or password.');
  }

  return payload.data;
}

async function handleLogin() {
  hideError();

  if (!validateInputs()) return;

  const username = usernameInput.value.trim();
  const password = passwordInput.value;
  const remember = rememberCheck ? rememberCheck.checked : false;

  setLoading(true);

  try {
    const user = await authenticate(username, password, remember);
    saveSession(user, remember);
    window.location.replace(user.redirect || getRedirectForRole(user.role));
  } catch (error) {
    showError(error.message || 'Incorrect username or password.');
    if (passwordInput) {
      passwordInput.value = '';
      passwordInput.focus();
    }
  } finally {
    setLoading(false);
  }
}

function togglePasswordVisibility() {
  if (!passwordInput || !eyeIcon || !eyeBtn) return;

  const hidden = passwordInput.type === 'password';
  passwordInput.type = hidden ? 'text' : 'password';
  eyeIcon.className = hidden ? 'ti ti-eye-off' : 'ti ti-eye';
  eyeBtn.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
}

function openForgotModal() {
  if (!forgotModal) return;
  if (resetEmailInput) resetEmailInput.value = '';
  forgotModal.classList.add('show');
  resetEmailInput && resetEmailInput.focus();
}

function closeForgotModal() {
  forgotModal && forgotModal.classList.remove('show');
}

function handleForgotPassword() {
  const email = resetEmailInput ? resetEmailInput.value.trim() : '';
  closeForgotModal();

  if (errorMsg) {
    errorMsg.textContent = email
      ? `Reset request sent to ${email}. Please wait for admin confirmation.`
      : 'Reset request sent. Please contact your administrator.';
  }

  if (errorBox) {
    errorBox.style.background = '#e1f5ee';
    errorBox.style.borderColor = '#aad8c5';
    errorBox.style.color = '#085041';
    errorBox.classList.add('show');

    setTimeout(() => {
      hideError();
      errorBox.style.background = '';
      errorBox.style.borderColor = '';
      errorBox.style.color = '';
    }, 4000);
  }
}

(function checkExistingSession() {
  const session = getSession();
  if (isSessionValid(session)) {
    window.location.replace(getRedirectForRole(session.role));
  }
})();

(function restoreRememberedUser() {
  const remembered = localStorage.getItem(REMEMBER_KEY);
  const session = getSession();

  if (remembered === 'true' && session && session.username && usernameInput && rememberCheck) {
    usernameInput.value = session.username;
    rememberCheck.checked = true;
  }
})();

if (eyeBtn) eyeBtn.addEventListener('click', togglePasswordVisibility);
if (loginBtn) loginBtn.addEventListener('click', handleLogin);

if (usernameInput) {
  usernameInput.addEventListener('keydown', event => {
    if (event.key === 'Enter') {
      event.preventDefault();
      passwordInput && passwordInput.focus();
    }
  });
  usernameInput.addEventListener('input', hideError);
}

if (passwordInput) {
  passwordInput.addEventListener('keydown', event => {
    if (event.key === 'Enter') {
      event.preventDefault();
      handleLogin();
    }
  });
  passwordInput.addEventListener('input', hideError);
}

if (forgotLink) {
  forgotLink.addEventListener('click', event => {
    event.preventDefault();
    openForgotModal();
  });
}

if (cancelReset) cancelReset.addEventListener('click', closeForgotModal);
if (confirmReset) confirmReset.addEventListener('click', handleForgotPassword);

if (forgotModal) {
  forgotModal.addEventListener('click', event => {
    if (event.target === forgotModal) closeForgotModal();
  });
}

if (resetEmailInput) {
  resetEmailInput.addEventListener('keydown', event => {
    if (event.key === 'Enter') {
      event.preventDefault();
      handleForgotPassword();
    }
  });
}

document.addEventListener('keydown', event => {
  if (event.key === 'Escape') closeForgotModal();
});

(function addSpinStyle() {
  if (document.getElementById('pawpos-spin-style')) return;

  const style = document.createElement('style');
  style.id = 'pawpos-spin-style';
  style.textContent = '@keyframes spin{to{transform:rotate(360deg)}}.spin{display:inline-block;animation:spin .8s linear infinite}';
  document.head.appendChild(style);
})();
