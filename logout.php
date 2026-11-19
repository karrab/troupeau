<?php
require 'includes/functions.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
check_csrf();
deconnecter();
flash('Vous êtes déconnecté.', 'info');
redirect('login.php');
