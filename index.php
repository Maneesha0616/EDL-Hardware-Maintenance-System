<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hardware Maintenance System | Homepage</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="images/hardware_logo.png">
    <link rel="stylesheet" href="modern-ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --nav-height: 90px;
            --primary: #00416a;
            --primary-light: #0084d1;
            --primary-dark: #002b47;
            --primary-gradient: linear-gradient(135deg, #00416a 0%, #0084d1 100%);
            --background: #f8fafc;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --glass-card-bg: rgba(255, 255, 255, 0.95);
            --glass-border: rgba(226, 232, 240, 0.8);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            overflow-x: hidden;
            background: var(--background);
            font-family: 'Inter', sans-serif;
        }

        .hero {
            position: relative;
            height: 100vh;
            min-height: 700px;
            background: linear-gradient(rgba(248, 250, 252, 0.7), rgba(248, 250, 252, 0.9)), url('images/hms_premium_hero_v2.png');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            display: flex;
            align-items: center;
            overflow: hidden;
        }

        .shape {
            position: absolute;
            background: var(--primary-gradient);
            filter: blur(80px);
            opacity: 0.15;
            border-radius: 50%;
            z-index: 1;
            animation: pulse 10s infinite alternate;
        }

        .shape-1 {
            width: 600px;
            height: 600px;
            top: -150px;
            right: -150px;
            animation-delay: 0s;
        }

        .shape-2 {
            width: 400px;
            height: 400px;
            bottom: -100px;
            left: -100px;
            animation-delay: -5s;
        }

        @keyframes pulse {
            0% {
                transform: scale(1) translate(0, 0);
            }

            100% {
                transform: scale(1.1) translate(20px, 20px);
            }
        }

        .hero-content {
            position: relative;
            z-index: 2;
            max-width: 900px;
            color: var(--text-main);
            padding: 0 2rem;
        }

        .hero h1 {
            font-size: clamp(2.8rem, 6vw, 4.8rem);
            line-height: 1.05;
            font-weight: 800;
            margin-bottom: 2rem;
            letter-spacing: -0.03em;
            text-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            color: var(--primary-dark);
        }

        .hero h1 span {
            background: linear-gradient(to right, #00416a, #0084d1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: block;
        }

        .hero p {
            font-size: 1.25rem;
            color: var(--text-muted);
            margin-bottom: 3.5rem;
            max-width: 650px;
            line-height: 1.7;
            font-weight: 400;
        }

        .nav {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: var(--nav-height);
            display: flex;
            align-items: center;
            z-index: 1000;
            transition: var(--transition);
            background: transparent;
            padding: 0 2rem;
        }

        .nav.scrolled {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.05);
            height: 85px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 15px;
            text-decoration: none;
            color: var(--primary-dark);
            font-weight: 800;
            font-size: 1.6rem;
            transition: var(--transition);
        }

        .logo img {
            width: 75px;
            height: 75px;
            border-radius: 16px;
            object-fit: cover;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .features {
            padding: 140px 0;
            background: var(--background);
            position: relative;
        }

        .section-header {
            text-align: center;
            margin-bottom: 90px;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }

        .section-header h2 {
            font-size: 3.2rem;
            color: var(--primary);
            margin-bottom: 1.5rem;
            font-weight: 800;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
            gap: 3rem;
        }

        .feature-card {
            padding: 4rem 3rem;
            text-align: center;
            background: var(--glass-card-bg);
            transition: var(--transition);
            border: 1px solid var(--glass-border);
            position: relative;
            overflow: hidden;
            border-radius: 24px;
        }

        .feature-card:hover {
            transform: translateY(-20px);
            background: white;
            box-shadow: 0 30px 60px rgba(0, 65, 106, 0.08);
            border-color: var(--primary);
        }

        .icon-wrapper {
            width: 90px;
            height: 90px;
            background: rgba(0, 65, 106, 0.05);
            color: var(--primary);
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 2.5rem;
            font-size: 2.2rem;
            transition: var(--transition);
        }

        .feature-card:hover .icon-wrapper {
            background: var(--primary);
            color: white;
            box-shadow: 0 15px 30px rgba(0, 65, 106, 0.2);
        }

        .cta-section {
            padding: 120px 0;
            background: var(--primary-gradient);
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        footer {
            padding: 80px 0;
            background: #ffffff;
            color: var(--text-muted);
            text-align: center;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
        }

        /* Hover styles for buttons */
        #loginBtn {
            transition: var(--transition);
        }

        #loginBtn:hover {
            background: var(--primary) !important;
            color: white !important;
            box-shadow: 0 10px 20px rgba(0, 65, 106, 0.15);
            transform: translateY(-2px);
        }
    </style>
</head>

<body>

    <nav class="nav" id="mainNav">
        <div class="container" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
            <a href="#" class="logo">
                <img src="images/hardware_logo.png" alt="HMS Logo">
                <span>HMS</span>
            </a>
            <div style="display: flex; gap: 2rem; align-items: center;">
                <a href="login.php" class="btn btn-outline"
                    style="border: 2px solid var(--primary); color: var(--primary); padding: 0.8rem 2.2rem; border-radius: 12px; font-weight: 700; text-decoration: none;"
                    id="loginBtn">Login</a>
                <a href="register.php" class="btn btn-primary"
                    style="background: var(--primary); color: white; padding: 0.8rem 2.2rem; border-radius: 12px; font-weight: 700; text-decoration: none; box-shadow: 0 10px 20px rgba(0,0,0,0.2);">Sign
                    Up</a>
            </div>
        </div>
    </nav>

    <header class="hero">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>

        <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 2rem;">
            <div class="hero-content animate-fade-in">
                <h1>Manage Your Hardware <span>Effortlessly.</span></h1>
                <p>A simple system to track and maintain your hardware. Monitor your assets, manage equipment repairs,
                    and control spare parts all in one place.</p>
                <div style="display: flex; gap: 2rem; flex-wrap: wrap;">
                    <a href="login.php" class="btn btn-primary"
                        style="background: var(--primary); color: white; padding: 1.25rem 3.5rem; border-radius: 100px; font-size: 1.15rem; font-weight: 700; text-decoration: none; display: flex; align-items: center; gap: 10px;">
                        Open System <i class="fas fa-arrow-right"></i>
                    </a>
                    <a href="#features" class="btn"
                        style="background: rgba(255,255,255,0.1); color: var(--primary); border: 2px solid var(--primary); padding: 1.25rem 3.5rem; border-radius: 100px; font-size: 1.15rem; font-weight: 700; text-decoration: none;">
                        Learn More
                    </a>
                </div>
            </div>
        </div>
    </header>

    <section id="features" class="features">
        <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 2rem;">
            <div class="section-header animate-fade-in">
                <h2>Our Main Features</h2>
                <p style="color: var(--text-muted); font-size: 1.25rem; line-height: 1.6;">Tools built to help the
                    Hardware Maintenance Division work better and faster.</p>
            </div>

            <div class="grid">
                <div class="feature-card animate-fade-in">
                    <div class="icon-wrapper"><i class="fas fa-screwdriver-wrench"></i></div>
                    <h3>Manage Repairs</h3>
                    <p style="color: var(--text-muted); margin-top: 1.2rem; font-size: 1.1rem;">Easily report hardware
                        problems and track the status of repairs in real-time.</p>
                </div>
                <div class="feature-card animate-fade-in">
                    <div class="icon-wrapper"><i class="fas fa-layer-group"></i></div>
                    <h3>Stock Management</h3>
                    <p style="color: var(--text-muted); margin-top: 1.2rem; font-size: 1.1rem;">Keep track of every
                        spare part and get alerts when you need to buy more stock.</p>
                </div>
                <div class="feature-card animate-fade-in">
                    <div class="icon-wrapper"><i class="fas fa-brain"></i></div>
                    <h3>Easy Reports</h3>
                    <p style="color: var(--text-muted); margin-top: 1.2rem; font-size: 1.1rem;">View simple charts and
                        reports to see how the maintenance team is performing.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="cta-section">
        <div class="container animate-fade-in" style="max-width: 1200px; margin: 0 auto; padding: 0 2rem;">
            <h2 style="font-size: 3.5rem; margin-bottom: 2.5rem; font-weight: 800;">Start Using the System</h2>
            <p
                style="margin-bottom: 4rem; font-size: 1.4rem; opacity: 0.95; max-width: 700px; margin-left: auto; margin-right: auto;">
                Join the Hardware Maintenance System today to make your work easier.</p>
            <a href="register.php" class="btn"
                style="background: white; color: var(--primary); padding: 1.5rem 5rem; border-radius: 100px; font-size: 1.25rem; font-weight: 700; box-shadow: 0 15px 40px rgba(0,0,0,0.15); text-decoration: none; display: inline-block;">
                Register Now
            </a>
        </div>
    </section>

    <footer>
        <div class="container">
            <img src="images/hardware_logo.png" alt="Logo"
                style="width: 50px; border-radius: 10px; margin-bottom: 1rem;">
            <h3>Hardware Maintenance System</h3>
            <p>&copy; <?php echo date('Y'); ?> Electricity Distribution Lanka (Private) Limited</p>
            <p style="margin-top: 15px; font-size: 0.9rem; opacity: 0.5;">Helping you manage hardware tools and
                maintenance tasks.</p>
        </div>
    </footer>

    < <script>
        // Navbar scroll effect
        window.addEventListener('scroll', function () {
        const nav = document.getElementById('mainNav');
        if (window.scrollY > 50) {
        nav.classList.add('scrolled');
        } else {
        nav.classList.remove('scrolled');
        }
        });
        </script>
</body>

</html>