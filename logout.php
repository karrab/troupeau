<?php
// Déconnexion (POST + jeton CSRF pour éviter les déconnexions forcées)
define('PAGE_PUBLIQUE', true);
require 'includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
check_csrf();

logout_user();
session_start();
flash('Vous êtes déconnecté. À bientôt !', 'info');
redirect('login.php');
