<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth_guard.php';
require_no_character_yet();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFK RPG - Create Character</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="dashboard-layout">
        
        <!-- Left Persistent Navigation Sidebar -->
        <aside class="sidebar">
            <div class="logo-area">
                <div class="logo-icon">⚔️</div>
                <h2>AFK RPG</h2>
            </div>
            
            <nav class="nav-links">
                <!-- Links disabled/styled out during character generation process -->
                <a href="#" class="nav-item disabled">🏠 Home</a>
                <a href="#" class="nav-item disabled">⚔️ Battle</a>
                <a href="#" class="nav-item disabled">👤 Character</a>
                <a href="#" class="nav-item disabled">📦 Inventory</a>
                <a href="#" class="nav-item disabled">💡 Skills</a>
                <a href="#" class="nav-item disabled">⚒️ Crafting</a>
                <a href="#" class="nav-item disabled">📜 Combat Log</a>
            </nav>

            <div class="logout-area">
                <a href="logout.php" class="logout-btn">
                    <span>↪</span> Logout
                </a>
            </div>
        </aside>

        <!-- Right Main Panel Container -->
        <main class="main-content">
            <div class="content-header">
                <h1>Create Your Character</h1>
                <p>Choose your class and start your journey.</p>
            </div>

            <!-- Populated if class-selection-submit.php redirects back with ?error=... -->
            <?php if (isset($_GET['error'])): ?>
                <p style="color:#f87171; text-align:center; margin-bottom:20px;">
                    <?php
                        $errors = [
                            'invalid_class'   => 'Please choose one of the three classes.',
                            'creation_failed' => 'Something went wrong creating your character. Please try again.',
                        ];
                        echo htmlspecialchars($errors[$_GET['error']] ?? 'Something went wrong. Please try again.');
                    ?>
                </p>
            <?php endif; ?>

            <!-- Horizontal Choice Row Mapping — each card is now its own real form,
                 no JavaScript needed to actually create the character. -->
            <div class="class-selection-row">

                <!-- Melee Card -->
                <form class="selection-card" action="class-selection-submit.php" method="POST">
                    <div class="card-icon-wrapper">⚔️</div>
                    <h3>Melee</h3>
                    <p>Weilds swords, high attack and balanced stats.</p>
                    <input type="hidden" name="skill_name" value="Melee">
                    <button type="submit" class="select-btn btn-blue">Select</button>
                </form>

                <!-- Ranged Card -->
                <form class="selection-card" action="class-selection-submit.php" method="POST">
                    <div class="card-icon-wrapper">🏹</div>
                    <h3>Ranged</h3>
                    <p>Uses bows, high range and critical chance.</p>
                    <input type="hidden" name="skill_name" value="Ranged">
                    <button type="submit" class="select-btn btn-green">Select</button>
                </form>

                <!-- Magic Card -->
                <form class="selection-card" action="class-selection-submit.php" method="POST">
                    <div class="card-icon-wrapper">⭐</div>
                    <h3>Magic</h3>
                    <p>Weilds wands, high magic power and mana.</p>
                    <input type="hidden" name="skill_name" value="Magic">
                    <button type="submit" class="select-btn btn-purple">Select</button>
                </form>

            </div>
        </main>

    </div>
</body>
</html>