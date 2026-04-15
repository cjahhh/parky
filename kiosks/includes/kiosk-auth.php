<?php
// kiosks/includes/kiosk-auth.php
// Include at the top of every kiosk page (before any HTML output)


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


define('KIOSK_PIN', '1234'); // Change this, or move to config/


$isAdmin = isset($_SESSION['admin_id']);
$isKiosk = isset($_SESSION['kiosk_active']) && $_SESSION['kiosk_active'] === true;


// Handle PIN submission
$pinError = '';
if (!$isAdmin && !$isKiosk && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kiosk_pin'])) {
    if ($_POST['kiosk_pin'] === KIOSK_PIN) {
        $_SESSION['kiosk_active'] = true;
        $isKiosk = true;
        // Reload the page cleanly (clears POST data)
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $pinError = 'Incorrect PIN. Please try again.';
    }
}


// Block if neither admin nor activated kiosk — show PIN screen
if (!$isAdmin && !$isKiosk) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Parky Kiosk — PIN Required</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@500;700;800&display=swap" rel="stylesheet">
        <style>
            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
            :root {
                --bg: #151815; --bg2: #1a1e1a; --surface: #252a25; --surface2: #2c322c;
                --border2: rgba(255,255,255,0.11); --text: #eaf2ea; --muted: #7a907a; --muted2: #4f5f4f;
                --emerald: #34d399; --emerald2: #10b981; --emeraldbg: rgba(52,211,153,0.08);
                --emeraldbg2: rgba(52,211,153,0.15); --danger: #f87171; --dangerbg: rgba(248,113,113,0.10);
            }
            body {
                font-family: 'Nunito', sans-serif;
                background: var(--bg);
                color: var(--text);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .pin-card {
                background: var(--bg2);
                border: 1px solid var(--border2);
                border-radius: 20px;
                padding: 2.5rem 2rem;
                width: 100%;
                max-width: 360px;
                text-align: center;
            }
            .pin-logo {
                width: 52px; height: 52px;
                background: var(--emeraldbg2);
                border: 1.5px solid rgba(52,211,153,0.3);
                border-radius: 14px;
                display: flex; align-items: center; justify-content: center;
                font-size: 1.1rem; font-weight: 800; color: var(--emerald);
                margin: 0 auto 1.25rem;
            }
            .pin-card h1 { font-size: 1.1rem; font-weight: 800; color: var(--text); margin-bottom: 5px; }
            .pin-card p  { font-size: 0.82rem; color: var(--muted); font-weight: 500; margin-bottom: 1.75rem; line-height: 1.5; }
            .pin-input {
                width: 100%;
                background: var(--surface);
                border: 1.5px solid var(--border2);
                border-radius: 11px;
                padding: 12px 16px;
                font-family: 'Nunito', sans-serif;
                font-size: 1.1rem;
                font-weight: 800;
                color: var(--text);
                text-align: center;
                letter-spacing: 0.3em;
                margin-bottom: 1rem;
                outline: none;
                transition: border-color 0.2s;
            }
            .pin-input:focus { border-color: rgba(52,211,153,0.5); }
            .pin-btn {
                width: 100%;
                background: var(--emerald2);
                color: #0a1a12;
                border: none;
                border-radius: 11px;
                padding: 12px;
                font-family: 'Nunito', sans-serif;
                font-size: 0.92rem;
                font-weight: 800;
                cursor: pointer;
                transition: background 0.2s;
            }
            .pin-btn:hover { background: var(--emerald); }
            .pin-error {
                background: var(--dangerbg);
                border: 1px solid rgba(248,113,113,0.2);
                border-radius: 9px;
                padding: 9px 12px;
                font-size: 0.8rem;
                font-weight: 700;
                color: var(--danger);
                margin-bottom: 1rem;
            }
            /* Numpad */
            .numpad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 1rem 0; }
            .np-btn {
                background: var(--surface);
                border: 1px solid var(--border2);
                border-radius: 10px;
                padding: 14px;
                font-family: 'Nunito', sans-serif;
                font-size: 1rem;
                font-weight: 800;
                color: var(--text);
                cursor: pointer;
                transition: background 0.15s;
            }
            .np-btn:hover   { background: var(--surface2); }
            .np-btn:active  { transform: scale(0.96); }
            .np-btn.clear   { color: var(--danger); font-size: 0.78rem; }
            .np-btn.confirm { background: var(--emeraldbg2); color: var(--emerald); border-color: rgba(52,211,153,0.3); }
            .np-btn.confirm:hover { background: var(--emerald2); color: #0a1a12; }
        </style>
    </head>
    <body>
        <div class="pin-card">
            <div class="pin-logo">P</div>
            <h1>Kiosk Access</h1>
            <p>Enter the kiosk PIN to activate this terminal.</p>


            <?php if ($pinError): ?>
                <div class="pin-error"><?= htmlspecialchars($pinError) ?></div>
            <?php endif; ?>


            <form method="POST" id="pinForm">
                <input
                    type="password"
                    name="kiosk_pin"
                    id="pinDisplay"
                    class="pin-input"
                    maxlength="10"
                    placeholder="• • • •"
                    autocomplete="off"
                    readonly
                >
                <!-- Numpad -->
                <div class="numpad">
                    <?php foreach ([1,2,3,4,5,6,7,8,9] as $n): ?>
                        <button type="button" class="np-btn" onclick="appendPin('<?= $n ?>')"><?= $n ?></button>
                    <?php endforeach; ?>
                    <button type="button" class="np-btn clear" onclick="clearPin()">Clear</button>
                    <button type="button" class="np-btn" onclick="appendPin('0')">0</button>
                    <button type="submit" class="np-btn confirm">Enter</button>
                </div>
            </form>
        </div>
        <script>
            function appendPin(val) {
                const f = document.getElementById('pinDisplay');
                if (f.value.length < 10) f.value += val;
            }
            function clearPin() {
                document.getElementById('pinDisplay').value = '';
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}
// If we reach here, access is granted — the kiosk page continues loading.

