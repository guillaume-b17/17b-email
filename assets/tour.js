const STORAGE_PREFIX = 'tour_seen_v1:';

function qs(selector) {
  if (!selector) return null;
  try {
    return document.querySelector(selector);
  } catch {
    return null;
  }
}

function safeSetItem(key, value) {
  try {
    localStorage.setItem(key, value);
  } catch {
    // ignore
  }
}

function safeGetItem(key) {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}

function buildOverlay() {
  const overlay = document.createElement('div');
  overlay.className = 'tour-overlay';
  overlay.innerHTML = `
    <div class="tour-modal" role="dialog" aria-modal="true" aria-label="Tutoriel">
      <div class="tour-modal-header">
        <div class="tour-title">Tutoriel</div>
        <button type="button" class="tour-close" aria-label="Fermer">✕</button>
      </div>
      <div class="tour-body"></div>
      <div class="tour-footer">
        <div class="tour-progress"></div>
        <div class="tour-actions">
          <button type="button" class="tour-prev">Précédent</button>
          <button type="button" class="tour-next">Suivant</button>
        </div>
      </div>
    </div>
  `;
  return overlay;
}

function scrollIntoViewIfNeeded(el) {
  try {
    el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
  } catch {
    // ignore
  }
}

function getTourKeyFromPath(pathname) {
  if (pathname === '/compte') return 'account';
  if (pathname === '/compte/repondeur') return 'responder';
  if (pathname === '/compte/redirections') return 'redirections';
  return null;
}

function tours() {
  return {
    account: [
      {
        selector: '[data-tour="nav"]',
        text: `Navigation principale : ici tu accèdes au Compte, au Répondeur et aux Redirections. Le bouton aide relance ce tutoriel.`
      },
      {
        selector: '[data-tour="card-responder"]',
        text: `Répondeur : active un message d’absence avec une période (début/retour) et un message préenregistré.`
      },
      {
        selector: '[data-tour="card-redirections"]',
        text: `Redirections : gère les redirections liées à ton compte (sortantes et entrantes).`
      },
    ],
    responder: [
      {
        selector: '#responder_starts_at',
        text: `Dates : la date de début ne peut pas être antidatée. Pense à renseigner une date de retour.`
      },
      {
        selector: '#responder_preset',
        text: `Message : tu peux choisir un message préenregistré (selon le format).`
      },
      {
        selector: '#responder_message',
        text: `Variables : tu peux copier/coller les variables (ex: {date_fin}) et elles sont remplacées automatiquement.`
      },
    ],
    redirections: [
      {
        selector: '[data-tour="outgoing-section"]',
        text: `Redirections sortantes : tu peux créer uniquement des redirections de ton compte vers une autre adresse (pas l’inverse).`
      },
      {
        selector: 'form[action$="/compte/redirections/creer"]',
        text: `Programmation : tu peux définir une période. L’activation est appliquée via un cron (jusqu’à ~30 min de délai).`
      },
    ]
  };
}

export function initTour() {
  const helpBtn = document.getElementById('help-tour');
  const tourKey = getTourKeyFromPath(window.location.pathname);
  if (!tourKey) return;

  const start = (force) => {
    const steps = tours()[tourKey] || [];
    if (steps.length === 0) return;

    const seenKey = `${STORAGE_PREFIX}${tourKey}`;
    if (!force && '1' === safeGetItem(seenKey)) return;

    let idx = 0;
    const overlay = buildOverlay();
    document.body.appendChild(overlay);

    const modal = overlay.querySelector('.tour-modal');
    const body = overlay.querySelector('.tour-body');
    const progress = overlay.querySelector('.tour-progress');
    const btnPrev = overlay.querySelector('.tour-prev');
    const btnNext = overlay.querySelector('.tour-next');
    const btnClose = overlay.querySelector('.tour-close');

    let highlighted = null;

    const clearHighlight = () => {
      if (highlighted) {
        highlighted.classList.remove('tour-highlight');
        highlighted = null;
      }
    };

    const render = () => {
      clearHighlight();
      const step = steps[idx];
      const target = qs(step.selector);
      if (target) {
        highlighted = target;
        target.classList.add('tour-highlight');
        scrollIntoViewIfNeeded(target);
      }

      body.textContent = step.text;
      progress.textContent = `${idx + 1} / ${steps.length}`;
      btnPrev.disabled = idx === 0;
      btnNext.textContent = idx === steps.length - 1 ? 'Terminer' : 'Suivant';
    };

    const close = () => {
      clearHighlight();
      overlay.remove();
      safeSetItem(seenKey, '1');
    };

    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) close();
    });
    btnClose.addEventListener('click', close);
    btnPrev.addEventListener('click', () => {
      if (idx > 0) idx -= 1;
      render();
    });
    btnNext.addEventListener('click', () => {
      if (idx < steps.length - 1) {
        idx += 1;
        render();
        return;
      }
      close();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') close();
    }, { once: true });

    render();
  };

  if (helpBtn) {
    helpBtn.addEventListener('click', () => start(true));
  }

  // Tutoriel auto à la première visite (uniquement si connecté: header présent).
  if (document.documentElement.getAttribute('data-theme-enabled') === '1') {
    window.setTimeout(() => start(false), 350);
  }
}

