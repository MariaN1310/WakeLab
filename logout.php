<?php
session_start();
require 'php/auth.php';

logoutUser();

header('Location: login.php');
exit;
