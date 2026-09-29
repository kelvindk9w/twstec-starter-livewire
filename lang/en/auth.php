<?php

declare(strict_types=1);

// Authentication strings (en). Every UI string goes through __().
// DOMAIN messages (refusals, notices, password policy) come from the
// twstec/kit-auth package; the screen strings live here. A key repeated here
// wins over the package one.

return [

    'password' => 'The provided password is incorrect.',

    // Email verification at sign-up (Twstec\Kit\Auth\Support\EmailVerification).
    'email_verification' => [
        'title' => 'Confirm your email',
        'intro' => 'We sent a confirmation link to :email. Open the email and click the link to unlock the dashboard.',
        'hint' => 'Didn\'t get it? Check your spam folder or request a new one.',
        'resend' => 'Resend email',
    ],

    // Two-step verification at LOGIN (TwoFactorLogin): code screen, flow
    // messages and the refusals to turn it on/off.
    'two_factor' => [
        'title' => 'Two-step verification',
        'intro' => 'We sent a 6-digit code to :email. Enter it below to finish signing in — it is valid for :minutes minutes.',
        'submit' => 'Confirm and sign in',
        'resend' => 'Send another code',
        'resend_hint' => 'Didn\'t get it? Check your spam folder. A new code invalidates the previous one.',
        'cancel' => 'Back to sign in',
        'enabled' => 'Two-step verification is on. From your next sign-in, we will ask for the code sent to your email.',
        'disabled' => 'Two-step verification is off. Sign-in asks for your password only again.',
    ],

    // UI strings (authentication forms/screens).
    'ui' => [
        'login_title' => 'Sign in',
        'login_submit' => 'Sign in',
        'login_link' => 'Already have an account? Sign in',
        'register_title' => 'Create account',
        'register_submit' => 'Create account',
        'register_link' => 'Create account',
        'name' => 'Full name',
        'email' => 'Email',
        'password' => 'Password',
        'new_password' => 'New password',
        'password_confirmation' => 'Confirm password',
        'remember_me' => 'Stay signed in',
        'forgot_password' => 'Forgot my password',
        'forgot_title' => 'Recover password',
        'forgot_subtitle' => 'Enter your email to receive the reset link.',
        'forgot_submit' => 'Send reset link',
        'reset_title' => 'Reset password',
        'reset_submit' => 'Reset password',
        'logout' => 'Sign out',
        'save' => 'Save',
        'transaction_password_title' => 'Transaction password',
        'transaction_password_subtitle' => 'Used to authorize sensitive actions (withdrawals, API keys). It must be different from the login password.',
        'current_transaction_password' => 'Current transaction password',
        'new_transaction_password' => 'New transaction password',
        'dashboard_title' => 'Dashboard',
        'dashboard_greeting' => 'Hello, :name',
        'dashboard_code' => 'Your user code',
    ],

];
