<?php

include __DIR__ . '/../model/user.php';

class LoginController {
    public function index() {
        // Display login form
        include __DIR__ . '/../view/login.php';
    }

    public function login() {
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $username = $_POST['username'];
            $password = $_POST['password'];

            $userModel = new User();
            $user = $userModel->authenticate($username, $password);

            if ($user) {
                session_regenerate_id(true);
                $_SESSION['user'] = $user;
                header('Location: ?action=index');
                exit;
            } else {
                $error = 'Invalid username or password';
                include __DIR__ . '/../view/login.php';
            }
        } else {
            $this->index();
        }
    }
}

?>