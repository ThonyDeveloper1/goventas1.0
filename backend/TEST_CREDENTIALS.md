# Test Credentials - GO Systems & Technology

## Credenciales de Prueba para Desarrollo y Testing

> ⚠️ **IMPORTANTE**: Estos son datos de EJEMPLO para testing. No usar en producción.

---

## 1. ADMIN

| Campo | Valor |
|-------|-------|
| **Email** | admin@gosystems.com |
| **DNI** | 12345678 |
| **Contraseña** | AdminTest123 |
| **Rol** | admin |

```bash
Login: admin@gosystems.com
Password: AdminTest123
```

---

## 2. VENDEDORA

| Campo | Valor |
|-------|-------|
| **Email** | vendedora@gosystems.com |
| **DNI** | 87654321 |
| **Nombre** | María García González |
| **Contraseña** | VendedoraTest123 |
| **Rol** | vendedora |

```bash
Login: 87654321 (DNI) o vendedora@gosystems.com
Password: VendedoraTest123
```

---

## 3. SUPERVISOR

| Campo | Valor |
|-------|-------|
| **Email** | supervisor@gosystems.com |
| **DNI** | 11223344 |
| **Nombre** | Carlos López Pérez |
| **Contraseña** | SupervisorTest123 |
| **Rol** | supervisor |

```bash
Login: 11223344 (DNI) o supervisor@gosystems.com
Password: SupervisorTest123
```

---

## Cómo Crear Estos Usuarios en Base de Datos

### Opción 1: Via Laravel Tinker
```bash
php artisan tinker

# Admin
User::create([
  'name' => 'Admin User',
  'email' => 'admin@gosystems.com',
  'dni' => '12345678',
  'password' => 'AdminTest123',
  'role' => 'admin',
  'active' => true
]);

# Vendedora
User::create([
  'name' => 'María García González',
  'email' => 'vendedora@gosystems.com',
  'dni' => '87654321',
  'password' => 'VendedoraTest123',
  'role' => 'vendedora',
  'active' => true
]);

# Supervisor
User::create([
  'name' => 'Carlos López Pérez',
  'email' => 'supervisor@gosystems.com',
  'dni' => '11223344',
  'password' => 'SupervisorTest123',
  'role' => 'supervisor',
  'active' => true
]);
```

### Opción 2: Via API
```bash
curl -X POST http://localhost:8000/api/users \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "María García González",
    "email": "vendedora@gosystems.com",
    "dni": "87654321",
    "password": "VendedoraTest123",
    "role": "vendedora"
  }'
```

---

## Permisos por Rol

- **Admin**: Acceso total a todas las funciones
- **Vendedora**: Gestión de clientes, reportes, pagos
- **Supervisor**: Supervisión de instalaciones, reportes

---

*Última actualización: 2026-03-25*
