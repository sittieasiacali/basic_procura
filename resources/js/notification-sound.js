function createUnreadTracker(e = () => {
}) {
  let t = false, n = "", r = /* @__PURE__ */ new Set();
  return (a) => {
    if (!Array.isArray(a)) return false;
    const o = a.reduce((c, l) => l.created_at > c ? l.created_at : c, n), s = t ? a.filter((c) => c.created_at >= n && !r.has(c.id)) : [], i = s.length > 0;
    return r = /* @__PURE__ */ new Set([...r, ...a.map((c) => c.id)]), o > n && (r = new Set(a.filter((c) => c.created_at === o).map((c) => c.id))), n = o, t = true, i && e(s[0]), i;
  };
}
const tones = { chime: [[659.25, 0, 0.28], [880, 0.16, 0.42]], bell: [[523.25, 0, 0.55], [1046.5, 0, 0.35]], pulse: [[740, 0, 0.15], [740, 0.23, 0.15]], marimba: [[523.25, 0, 0.24], [659.25, 0.25, 0.24], [783.99, 0.5, 0.32]], sparkle: [[1046.5, 0, 0.2], [1318.51, 0.2, 0.2], [1567.98, 0.4, 0.36]], doorbell: [[659.25, 0, 0.4], [523.25, 0.5, 0.55]], melody: [[523.25, 0, 0.24], [659.25, 0.3, 0.24], [783.99, 0.6, 0.24], [1046.5, 0.9, 0.28]], sonar: [[440, 0, 0.75], [554.37, 0.8, 0.35]] }, toneLabels = { chime: "Gentle chime", bell: "Bell", pulse: "Double beep", marimba: "Marimba", sparkle: "Sparkle", doorbell: "Doorbell", melody: "Morning melody", sonar: "Soft sonar" }, activeTones = /* @__PURE__ */ new WeakMap(), toneDuration = 4;
function stopNotificationTone(e) {
  if (e) {
    for (const t of activeTones.get(e) || []) try {
      t.stop(e.currentTime);
    } catch {
    }
    activeTones.delete(e);
  }
}
function playNotificationTone(e, t = "chime") {
  if (e.state !== "running") return false;
  stopNotificationTone(e);
  const n = e.currentTime + 0.02, r = Object.hasOwn(tones, t) ? tones[t] : tones.chime, a = [];
  for (let i = 0; i < toneDuration; i += 1.2) for (const [c, l, d] of r) {
    const u = i + l;
    u < toneDuration - 0.04 && a.push([c, u, Math.min(d, toneDuration - u)]);
  }
  const o = a.at(-1);
  o[2] = toneDuration - o[1];
  const s = /* @__PURE__ */ new Set();
  activeTones.set(e, s);
  for (const [i, c, l] of a) {
    const d = e.createOscillator(), u = e.createGain();
    d.type = "sine", d.frequency.value = i, u.gain.setValueAtTime(0, n + c), u.gain.linearRampToValueAtTime(0.14, n + c + 0.015), u.gain.exponentialRampToValueAtTime(1e-3, n + c + Math.max(0.016, l - 0.02)), d.connect(u), u.connect(e.destination), u.gain.linearRampToValueAtTime(0, n + c + l), s.add(d), d.onended = () => {
      d.disconnect(), u.disconnect(), s.delete(d);
    }, d.start(n + c), d.stop(n + c + l);
  }
  return true;
}
function initializeNotificationSound() {
  const e = document.querySelector('meta[name="notification-sound-user"]')?.content;
  if (!e) return;
  const t = "asi-procura:notification-sound:" + e;
  let n = { enabled: false }, r = document.querySelector('meta[name="notification-sound-tone"]')?.content || "chime";
  const a = document.querySelector("[data-admin-notification-tone]");
  try {
    const E = JSON.parse(localStorage.getItem(t));
    E && (n = { enabled: E.enabled === true });
  } catch {
  }
  const o = !!document.querySelector("[data-campaign-notification-alert]");
  let s = !o;
  if (o) {
    try {
      s = JSON.parse(localStorage.getItem("asi-procura:campaign-alerts:" + e))?.decision === "enabled";
    } catch {
    }
    n.enabled = s;
  }
  const i = "asi-procura:alert-test:" + e, c = o && !a;
  let l = false;
  try {
    l = localStorage.getItem(i) === "1";
  } catch {
  }
  const d = () => window.dispatchEvent(new Event("procura:request-alert-consent"));
  let u;
  const p = window.AudioContext || window.webkitAudioContext, b = document.querySelector("[data-notification-sound-toggle]"), y = document.querySelector("[data-notification-sound-enable]"), C = document.querySelector("[data-notification-ringtone-label]"), h = document.querySelector("[data-notification-sound-test]"), m = document.querySelector("[data-campaign-alert-setup]"), w = document.querySelector("[data-notification-sound-status]"), q = createUnreadTracker((E) => {
    document.hidden || window.dispatchEvent?.(new CustomEvent("procura:new-notification", { detail: E }));
  }), g = () => {
    b && (b.checked = n.enabled, b.disabled = !p), y && (y.disabled = !p, y.textContent = s ? o ? "Activate sound" : n.enabled ? u?.state === "running" ? "Mute sound" : "Activate sound" : "Enable sound" : "Enable alerts", y.hidden = o && s, y.setAttribute("aria-pressed", String(n.enabled))), m && (m.hidden = o && s), C && (C.textContent = toneLabels[r] || "Gentle chime"), h && (h.disabled = !p, h.hidden = c && l), w && (w.textContent = p ? a ? "Tap Test sound to preview your choice before saving." : n.enabled ? u?.state === "running" ? "Sound is on while Procura is open." : "Tap Activate sound to activate audio on this page." : "Sound is off." : "Notification sound is unavailable in this browser.");
  }, L = () => {
    try {
      localStorage.setItem(t, JSON.stringify(n));
    } catch {
    }
    g();
  }, R = () => ((!u || u.state === "closed") && (u = new p(), u.addEventListener?.("statechange", g)), u), A = (E) => {
    if (!E?.target?.closest("[data-notification-sound-enable], [data-notification-sound-test], [data-notification-sound-toggle]") && !(!s || !p || !n.enabled || u?.state === "running")) try {
      R().resume().then(g).catch(g);
    } catch {
      g();
    }
  };
  document.addEventListener("pointerdown", A, { passive: true }), document.addEventListener("click", A), document.addEventListener("touchend", A, { passive: true }), document.addEventListener("keydown", A);
  const D = async () => {
    if (!s) {
      d();
      return;
    }
    if (p) try {
      if (await R().resume(), c && l) {
        g();
        return;
      }
      const E = playNotificationTone(u, a?.value || r);
      if (E && c) {
        l = true;
        try {
          localStorage.setItem(i, "1");
        } catch {
        }
        window.dispatchEvent(new Event("procura:alert-test-completed"));
      }
      !E && w ? w.textContent = "Tap Test sound again to activate audio." : g();
    } catch {
      w && (w.textContent = "Audio could not start. Tap Test sound to try again.");
    }
  };
  b?.addEventListener("change", () => {
    if (o && s) {
      b.checked = true;
      return;
    }
    if (!s && b.checked) {
      b.checked = false, d();
      return;
    }
    n.enabled = b.checked, L(), n.enabled ? D() : stopNotificationTone(u);
  }), y?.addEventListener("click", () => {
    if (!s) {
      d();
      return;
    }
    if (o) {
      D();
      return;
    }
    n.enabled && u?.state === "running" ? (n.enabled = false, stopNotificationTone(u), L()) : (n.enabled = true, L(), D());
  }), h?.addEventListener("click", D), window.addEventListener("procura:test-notification-alert", D), window.addEventListener("procura:alert-consent", (E) => {
    s = E.detail.enabled === true, n.enabled = s, L(), s && E.detail.preview !== false ? D() : s || stopNotificationTone(u);
  }), window.addEventListener("procura:stop-notification-alert", () => stopNotificationTone(u)), window.addEventListener("procura:notification-summary", (E) => {
    Object.hasOwn(tones, E.detail.ringtone) && (r = E.detail.ringtone, g()), q(E.detail.recent_unread) && n.enabled && !document.hidden && u && playNotificationTone(u, r);
  }), window.addEventListener("storage", (E) => {
    if (E.key === i) {
      l = E.newValue === "1", g();
      return;
    }
    if (E.key === t) try {
      const _ = JSON.parse(E.newValue);
      n = { enabled: o ? s : _?.enabled === true }, n.enabled || stopNotificationTone(u), g();
    } catch {
    }
  }), g();
}
initializeNotificationSound();
export {
  createUnreadTracker,
  initializeNotificationSound,
  playNotificationTone,
  stopNotificationTone
};
