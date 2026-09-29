<?php

declare(strict_types=1);

// Cadenas de autenticación (es). Toda cadena de UI pasa por __().
// Los mensajes del DOMINIO (rechazos, avisos, política de contraseña) vienen
// del paquete twstec/kit-auth; aquí quedan los de las pantallas. Una clave
// repetida aquí gana sobre la del paquete.

return [

    'password' => 'La contraseña ingresada es incorrecta.',

    // Verificación de correo en el registro (Twstec\Kit\Auth\Support\EmailVerification).
    'email_verification' => [
        'title' => 'Confirma tu correo',
        'intro' => 'Enviamos un enlace de confirmación a :email. Abre el correo y haz clic en el enlace para habilitar el panel.',
        'hint' => '¿No llegó? Revisa la carpeta de spam o solicita un nuevo envío.',
        'resend' => 'Reenviar correo',
    ],

    // Verificación en dos pasos en el INICIO DE SESIÓN (TwoFactorLogin):
    // pantalla del código, mensajes del flujo y los rechazos al activar/desactivar.
    'two_factor' => [
        'title' => 'Verificación en dos pasos',
        'intro' => 'Enviamos un código de 6 dígitos a :email. Escríbelo abajo para completar el inicio de sesión — es válido por :minutes minutos.',
        'submit' => 'Confirmar y entrar',
        'resend' => 'Enviar otro código',
        'resend_hint' => '¿No llegó? Revisa el spam. Un código nuevo invalida el anterior.',
        'cancel' => 'Volver al inicio de sesión',
        'enabled' => 'Verificación en dos pasos activada. Desde tu próximo inicio de sesión, pediremos el código enviado a tu correo.',
        'disabled' => 'Verificación en dos pasos desactivada. El inicio de sesión vuelve a pedir solo la contraseña.',
    ],

    // Cadenas de interfaz (formularios/pantallas de autenticación).
    'ui' => [
        'login_title' => 'Entrar',
        'login_submit' => 'Entrar',
        'login_link' => '¿Ya tienes cuenta? Entrar',
        'register_title' => 'Crear cuenta',
        'register_submit' => 'Crear cuenta',
        'register_link' => 'Crear cuenta',
        'name' => 'Nombre completo',
        'email' => 'Correo electrónico',
        'password' => 'Contraseña',
        'new_password' => 'Nueva contraseña',
        'password_confirmation' => 'Confirma la contraseña',
        'remember_me' => 'Mantener sesión iniciada',
        'forgot_password' => 'Olvidé mi contraseña',
        'forgot_title' => 'Recuperar contraseña',
        'forgot_subtitle' => 'Ingresa tu correo para recibir el enlace de restablecimiento.',
        'forgot_submit' => 'Enviar enlace de restablecimiento',
        'reset_title' => 'Restablecer contraseña',
        'reset_submit' => 'Restablecer contraseña',
        'logout' => 'Salir',
        'save' => 'Guardar',
        'transaction_password_title' => 'Contraseña de transacción',
        'transaction_password_subtitle' => 'Se usa para autorizar acciones sensibles (retiros, claves de API). Debe ser diferente de la contraseña de acceso.',
        'current_transaction_password' => 'Contraseña de transacción actual',
        'new_transaction_password' => 'Nueva contraseña de transacción',
        'dashboard_title' => 'Panel',
        'dashboard_greeting' => 'Hola, :name',
        'dashboard_code' => 'Tu código de usuario',
    ],

];
