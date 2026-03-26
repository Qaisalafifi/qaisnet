<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>سمارت كريتور - لوحة صاحب الشبكة</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap');

        :root {
            --blue-900: #1b1cff;
            --blue-700: #2e35ff;
            --cyan-500: #26b4da;
            --cyan-600: #1aa7cf;
            --pink-500: #ff2aa7;
            --pink-600: #f01698;
            --teal-100: #d9f3fb;
            --slate-900: #182031;
            --slate-700: #3e4c66;
            --card-bg: #ffffff;
            --shadow-lg: 0 22px 40px rgba(24, 32, 49, 0.12);
            --shadow-md: 0 12px 24px rgba(24, 32, 49, 0.12);
            --radius-xl: 22px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Cairo', sans-serif;
            background: radial-gradient(circle at top left, #d9f0ff, #f5fbff 45%, #ffffff 70%);
            color: var(--slate-900);
            min-height: 100vh;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(circle at 15% 20%, rgba(38, 180, 218, 0.18), transparent 45%),
                radial-gradient(circle at 85% 10%, rgba(255, 42, 167, 0.12), transparent 40%),
                radial-gradient(circle at 70% 80%, rgba(38, 180, 218, 0.12), transparent 45%);
            pointer-events: none;
            z-index: 0;
        }

        .app {
            position: relative;
            min-height: 100vh;
            z-index: 1;
        }

        .topbar {
            height: 60px;
            background: linear-gradient(110deg, var(--blue-900), var(--blue-700));
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 18px;
            box-shadow: 0 8px 22px rgba(27, 28, 255, 0.28);
        }

        .topbar__title {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }

        .menu-btn {
            background: transparent;
            border: none;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .menu-btn:hover {
            background: rgba(255, 255, 255, 0.18);
        }

        .content {
            padding: 18px 18px 42px;
            max-width: 1100px;
            margin: 0 auto;
        }

        .hero-card {
            background: linear-gradient(120deg, var(--pink-500), var(--pink-600));
            border-radius: var(--radius-xl);
            padding: 18px 20px;
            color: #fff;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            position: relative;
            overflow: hidden;
            animation: rise 0.8s ease both;
        }

        .hero-card::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 10% 10%, rgba(255, 255, 255, 0.24), transparent 45%);
            opacity: 0.8;
        }

        .hero-text {
            position: relative;
            z-index: 1;
        }

        .hero-text h2 {
            margin: 0 0 6px;
            font-size: 18px;
            font-weight: 700;
        }

        .hero-text p {
            margin: 0;
            font-size: 14px;
            opacity: 0.9;
        }

        .hero-count {
            font-size: 22px;
            font-weight: 700;
            margin-top: 6px;
        }

        .hero-icon {
            position: relative;
            z-index: 1;
            width: 60px;
            height: 60px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.2);
            display: grid;
            place-items: center;
        }

        .grid {
            display: grid;
            gap: 18px;
            margin-top: 22px;
        }

        .grid--four {
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        }

        .grid--three {
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }

        .tile {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 16px 12px;
            text-align: center;
            box-shadow: var(--shadow-md);
            display: grid;
            gap: 10px;
            align-items: center;
            justify-items: center;
            min-height: 140px;
            animation: rise 0.8s ease both;
            animation-delay: calc(var(--i) * 0.08s);
        }

        .tile-icon {
            width: 64px;
            height: 64px;
            border-radius: 18px;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #e8f3ff, #ffffff);
            box-shadow: inset 0 0 0 1px rgba(24, 32, 49, 0.05);
        }

        .tile span {
            font-size: 14px;
            font-weight: 600;
            color: var(--slate-900);
        }

        .drawer {
            position: fixed;
            top: 0;
            right: 0;
            height: 100%;
            width: 260px;
            background: linear-gradient(180deg, var(--cyan-500), var(--cyan-600));
            color: #fff;
            padding: 18px 16px;
            transform: translateX(110%);
            transition: transform 0.25s ease;
            box-shadow: -12px 0 24px rgba(0, 0, 0, 0.16);
            z-index: 10;
        }

        .drawer.open {
            transform: translateX(0);
        }

        .drawer-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
            font-weight: 700;
        }

        .drawer-list {
            display: grid;
            gap: 12px;
        }

        .drawer-item {
            background: rgba(255, 255, 255, 0.12);
            border-radius: 14px;
            padding: 10px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 14px;
        }

        .drawer-item svg {
            width: 18px;
            height: 18px;
        }

        .drawer-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(8, 10, 30, 0.3);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
            z-index: 5;
        }

        .drawer-backdrop.open {
            opacity: 1;
            pointer-events: auto;
        }

        @keyframes rise {
            from {
                opacity: 0;
                transform: translateY(12px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (min-width: 900px) {
            .topbar {
                height: 68px;
                padding: 0 28px;
            }

            .topbar__title {
                font-size: 20px;
            }

            .content {
                padding: 28px 24px 48px;
            }

            .hero-text h2 {
                font-size: 20px;
            }

            .hero-count {
                font-size: 26px;
            }
        }
    </style>
</head>
<body>
<div class="app">
    <header class="topbar">
        <button class="menu-btn" id="menuBtn" aria-label="القائمة">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="5" cy="12" r="1.5"/>
                <circle cx="12" cy="12" r="1.5"/>
                <circle cx="19" cy="12" r="1.5"/>
            </svg>
        </button>
        <div class="topbar__title">سمارت كريتور</div>
        <div style="width:40px"></div>
    </header>

    <aside class="drawer" id="drawer">
        <div class="drawer-header">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 3v6l4 2"/>
                <circle cx="12" cy="12" r="9"/>
            </svg>
            نسخة تجريبية
        </div>
        <div class="drawer-list">
            <div class="drawer-item">
                بحث
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="7"/>
                    <path d="m20 20-3.5-3.5"/>
                </svg>
            </div>
            <div class="drawer-item">
                خروج
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                    <path d="M10 17l5-5-5-5"/>
                    <path d="M15 12H3"/>
                </svg>
            </div>
            <div class="drawer-item">
                إنهاء
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 6 6 18"/>
                    <path d="M6 6l12 12"/>
                </svg>
            </div>
            <div class="drawer-item">
                طلب ترخيص التطبيق
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 3v12"/>
                    <path d="M8 7h8"/>
                    <path d="M5 21h14"/>
                </svg>
            </div>
            <div class="drawer-item">
                تواصل معنا
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 12a10 10 0 1 1-4-8"/>
                    <path d="M22 4v6h-6"/>
                </svg>
            </div>
        </div>
    </aside>
    <div class="drawer-backdrop" id="drawerBackdrop"></div>

    <main class="content">
        <section class="hero-card">
            <div class="hero-text">
                <h2>User Manager Cards</h2>
                <p>كروت اليوزرمنجر</p>
                <div class="hero-count">8686</div>
            </div>
            <div class="hero-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M7 21v-2a4 4 0 0 1 3-3.87"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
            </div>
        </section>

        <section class="grid grid--four">
            <div class="tile" style="--i:1">
                <div class="tile-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#2e35ff" stroke-width="2">
                        <path d="M2 12h4"/>
                        <path d="M18 12h4"/>
                        <path d="M6 8a6 6 0 0 1 12 0"/>
                        <path d="M8 16a4 4 0 0 1 8 0"/>
                    </svg>
                </div>
                <span>المتصلين النشطين</span>
            </div>
            <div class="tile" style="--i:2">
                <div class="tile-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#1aa7cf" stroke-width="2">
                        <path d="M4 7h16"/>
                        <path d="M4 12h16"/>
                        <path d="M4 17h10"/>
                        <circle cx="18" cy="17" r="2"/>
                    </svg>
                </div>
                <span>الأجهزة المتصلة</span>
            </div>
            <div class="tile" style="--i:3">
                <div class="tile-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#ff2aa7" stroke-width="2">
                        <path d="M3 13l9-9 9 9"/>
                        <path d="M9 21V9h6v12"/>
                    </svg>
                </div>
                <span>الهوتسبوت</span>
            </div>
            <div class="tile" style="--i:4">
                <div class="tile-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#3e4c66" stroke-width="2">
                        <circle cx="9" cy="8" r="3"/>
                        <circle cx="17" cy="8" r="3"/>
                        <path d="M2 20a5 5 0 0 1 14 0"/>
                        <path d="M12 20a4 4 0 0 1 8 0"/>
                    </svg>
                </div>
                <span>اليوزرمانجر</span>
            </div>
        </section>

        <section class="grid grid--three">
            <div class="tile" style="--i:5">
                <div class="tile-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#ffb703" stroke-width="2">
                        <path d="M12 1v22"/>
                        <path d="M5 5h14"/>
                        <path d="M7 9h10"/>
                        <path d="M9 13h6"/>
                    </svg>
                </div>
                <span>جلب التقارير</span>
            </div>
            <div class="tile" style="--i:6">
                <div class="tile-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#6c63ff" stroke-width="2">
                        <rect x="4" y="3" width="16" height="18" rx="2"/>
                        <path d="M8 7h8"/>
                        <path d="M8 12h8"/>
                        <path d="M8 16h5"/>
                    </svg>
                </div>
                <span>إدارة وتعديل القوالب</span>
            </div>
            <div class="tile" style="--i:7">
                <div class="tile-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#17a34a" stroke-width="2">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>
                    </svg>
                </div>
                <span>الإعدادات</span>
            </div>
        </section>
    </main>
</div>

<script>
    const drawer = document.getElementById('drawer');
    const backdrop = document.getElementById('drawerBackdrop');
    const menuBtn = document.getElementById('menuBtn');

    function toggleDrawer() {
        drawer.classList.toggle('open');
        backdrop.classList.toggle('open');
    }

    menuBtn.addEventListener('click', toggleDrawer);
    backdrop.addEventListener('click', toggleDrawer);
</script>
</body>
</html>
