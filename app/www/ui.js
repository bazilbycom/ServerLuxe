// ui.js - Global UI elements, dialogs, and navigation logic

window.haptic = function (type = 'impactLight') {
    // Debug log for development/testing
    console.log('[Haptic] Triggering:', type);

    const canCapacitorHaptic = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.Haptics;

    if (canCapacitorHaptic) {
        const { Haptics } = window.Capacitor.Plugins;
        try {
            switch (type) {
                case 'impactLight': Haptics.impact({ style: 'LIGHT' }); break;
                case 'impactMedium': Haptics.impact({ style: 'MEDIUM' }); break;
                case 'impactHeavy': Haptics.impact({ style: 'HEAVY' }); break;
                case 'vibrate': Haptics.vibrate(); break;
                case 'success': Haptics.notification({ type: 'SUCCESS' }); break;
                case 'warning': Haptics.notification({ type: 'WARNING' }); break;
                case 'error': Haptics.notification({ type: 'ERROR' }); break;
                default: Haptics.impact({ style: 'LIGHT' });
            }
        } catch (e) {
            console.warn('[Haptic] Capacitor error:', e);
            // Fallback to web vibration if plugin fails inside Capacitor
            if (navigator.vibrate) navigator.vibrate(10);
        }
    } else if (navigator.vibrate) {
        // Fallback for Web/Browser
        try {
            switch (type) {
                case 'error': navigator.vibrate([100, 50, 100]); break;
                case 'warning': navigator.vibrate([50, 30, 50]); break;
                case 'success': navigator.vibrate(20); break;
                default: navigator.vibrate(10);
            }
        } catch (e) {
            console.warn('[Haptic] Web Vibration error:', e);
        }
    }
};

window.uiAlert = function (msg) {
    window.haptic('impactLight');
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center; animation: fadeIn 0.1s ease; opacity: 1;';

        const card = document.createElement('div');
        card.style.cssText = 'background: #1e293b; color: #fff; width: 85%; max-width: 400px; padding: 1.5rem; border-radius: 1rem; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.5); text-align: center; font-family: sans-serif;';

        // SECURITY: Use innerHTML with static markup only, message goes in textContent
        card.innerHTML = `
            <div style="font-weight: 800; margin-bottom: 1rem; font-size: 1.1rem; color: #22d3ee;">Notification</div>
            <div id="msgContent" style="margin-bottom: 1.5rem; font-size: 0.9rem; color: #94a3b8; word-break: break-word;"></div>
            <button style="width: 100%; padding: 0.75rem; border-radius: 0.5rem; border: none; background: #22d3ee; color: #000; font-weight: 800; cursor: pointer;">OK</button>
        `;

        // Use textContent to prevent XSS
        card.querySelector('#msgContent').textContent = msg;

        const btn = card.querySelector('button');
        btn.onclick = () => {
            document.body.removeChild(overlay);
            resolve();
        };

        overlay.appendChild(card);
        document.body.appendChild(overlay);
    });
};

window.uiConfirm = function (msg) {
    window.haptic('impactMedium');
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center;';

        const card = document.createElement('div');
        card.style.cssText = 'background: #1e293b; color: #fff; width: 85%; max-width: 400px; padding: 1.5rem; border-radius: 1rem; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.5); text-align: center; font-family: sans-serif;';

        // SECURITY: Use innerHTML with static markup only, message goes in textContent
        card.innerHTML = `
            <div style="font-weight: 800; margin-bottom: 1rem; font-size: 1.1rem; color: #ff6b6b;">Action Required</div>
            <div id="msgContent" style="margin-bottom: 1.5rem; font-size: 0.9rem; color: #94a3b8; word-break: break-word;"></div>
            <div style="display: flex; gap: 1rem;">
                <button id="btnCancel" style="flex: 1; padding: 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(255,255,255,0.1); background: transparent; color: #fff; font-weight: 800; cursor: pointer;">CANCEL</button>
                <button id="btnOk" style="flex: 1; padding: 0.75rem; border-radius: 0.5rem; border: none; background: #ff6b6b; color: #000; font-weight: 800; cursor: pointer;">CONFIRM</button>
            </div>
        `;

        // Use textContent to prevent XSS
        card.querySelector('#msgContent').textContent = msg;

        overlay.appendChild(card);
        document.body.appendChild(overlay);

        card.querySelector('#btnOk').onclick = () => { document.body.removeChild(overlay); resolve(true); };
        card.querySelector('#btnCancel').onclick = () => { document.body.removeChild(overlay); resolve(false); };
    });
};

window.uiPrompt = function (msg, defaultVal = '') {
    window.haptic('impactMedium');
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center;';

        const card = document.createElement('div');
        card.style.cssText = 'background: #1e293b; color: #fff; width: 85%; max-width: 400px; padding: 1.5rem; border-radius: 1rem; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.5); text-align: center; font-family: sans-serif;';

        // SECURITY: Don't put user messages in innerHTML, use textContent instead
        card.innerHTML = `
            <div style="font-weight: 800; margin-bottom: 1rem; font-size: 1.1rem; color: #22d3ee;">Input Required</div>
            <div id="msgContent" style="margin-bottom: 0.5rem; font-size: 0.9rem; color: #94a3b8; word-break: break-word;"></div>
            <input type="text" id="promptInput" style="width: 100%; background: #0f172a; border: 1px solid rgba(255,255,255,0.1); padding: 0.75rem; border-radius: 0.5rem; color: #fff; margin-bottom: 1.5rem; outline: none; font-family: inherit;">
            <div style="display: flex; gap: 1rem;">
                <button id="btnCancel" style="flex: 1; padding: 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(255,255,255,0.1); background: transparent; color: #fff; font-weight: 800; cursor: pointer;">CANCEL</button>
                <button id="btnOk" style="flex: 1; padding: 0.75rem; border-radius: 0.5rem; border: none; background: #22d3ee; color: #000; font-weight: 800; cursor: pointer;">SUBMIT</button>
            </div>
        `;

        // Use textContent to prevent XSS
        card.querySelector('#msgContent').textContent = msg;
        card.querySelector('#promptInput').value = defaultVal;

        overlay.appendChild(card);
        document.body.appendChild(overlay);

        const input = card.querySelector('#promptInput');
        input.focus();

        card.querySelector('#btnOk').onclick = () => { document.body.removeChild(overlay); resolve(input.value); };
        card.querySelector('#btnCancel').onclick = () => { document.body.removeChild(overlay); resolve(null); };
    });
};

document.addEventListener('alpine:init', () => {
    // Add App BackButton listener if in Capacitor
    setTimeout(() => {
        if (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.App) {
            window.Capacitor.Plugins.App.addListener('backButton', async ({ canGoBack }) => {
                const event = new CustomEvent('hardwareBackPress', { cancelable: true });
                window.dispatchEvent(event);
                if (!event.defaultPrevented) {
                    const conf = await window.uiConfirm('Are you sure you want to close the app?');
                    if (conf) {
                        window.Capacitor.Plugins.App.exitApp();
                    }
                }
            });
        }
    }, 1000);
});

// Animations wrapper
window.switchApp = function (url) {
    document.body.style.transition = 'opacity 0.3s ease';
    document.body.style.opacity = '0';
    setTimeout(() => {
        window.location.href = url;
    }, 300);
};
