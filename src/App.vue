<script setup>
import { onMounted, ref } from 'vue'
import LabelDesigner from './components/LabelDesigner.vue'
import { checkPassword, fetchAuthStatus } from './utils/api.js'
import { clearStoredPassword, setStoredPassword } from './utils/authStorage.js'

// Sul server di produzione (MOJITO_PASSWORD impostata) il designer chiede la
// password prima di mostrare qualsiasi cosa. In sviluppo, senza la variabile,
// non cambia nulla.
const authChecked = ref(false)
const authRequired = ref(false)
const password = ref('')
const authError = ref('')
const verifying = ref(false)

async function refreshAuthStatus() {
  try {
    const status = await fetchAuthStatus()
    authRequired.value = Boolean(status.passwordRequired) && !status.authenticated
  } catch {
    // API non raggiungibile: il designer mostra i propri errori, la porta
    // resta aperta perche' senza server non c'e' niente da proteggere.
    authRequired.value = false
  } finally {
    authChecked.value = true
  }
}

async function submitPassword() {
  if (verifying.value) return
  authError.value = ''
  verifying.value = true

  try {
    setStoredPassword(password.value)
    await checkPassword(password.value)
    authRequired.value = false
    password.value = ''
  } catch (error) {
    clearStoredPassword()
    authError.value = error?.status === 401 ? 'Password errata.' : (error?.message || 'Errore di verifica.')
  } finally {
    verifying.value = false
  }
}

onMounted(refreshAuthStatus)
</script>

<template>
  <div v-if="!authChecked" class="auth-loading">Verifica accesso…</div>

  <div v-else-if="authRequired" class="auth-gate">
    <form class="auth-card" @submit.prevent="submitPassword">
      <h1>🍹 Mojito Label Designer</h1>
      <p>Questa installazione è protetta: inserisci la password per aprire il designer.</p>
      <input
        v-model="password"
        type="password"
        placeholder="Password"
        autocomplete="current-password"
        autofocus
      />
      <button type="submit" :disabled="verifying || password === ''">
        {{ verifying ? 'Verifica…' : 'Entra' }}
      </button>
      <p v-if="authError" class="auth-error">{{ authError }}</p>
    </form>
  </div>

  <LabelDesigner v-else />
</template>

<style scoped>
.auth-loading {
  min-height: 100vh;
  display: grid;
  place-items: center;
  color: #64748b;
  font-size: 0.95rem;
}

.auth-gate {
  min-height: 100vh;
  display: grid;
  place-items: center;
  background: #0f172a;
  padding: 1rem;
}

.auth-card {
  width: min(420px, 100%);
  background: #fff;
  border-radius: 14px;
  padding: 1.6rem 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.8rem;
  box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
}

.auth-card h1 {
  margin: 0;
  font-size: 1.15rem;
}

.auth-card p {
  margin: 0;
  color: #475569;
  font-size: 0.9rem;
}

.auth-card input {
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  padding: 0.6rem 0.8rem;
  font-size: 1rem;
}

.auth-card button {
  border: none;
  border-radius: 8px;
  padding: 0.65rem 0.9rem;
  background: #0f9d58;
  color: #fff;
  font-weight: 700;
  cursor: pointer;
}

.auth-card button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.auth-error {
  color: #b91c1c;
  font-weight: 600;
}
</style>
