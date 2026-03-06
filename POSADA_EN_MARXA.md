# Posada en marxa — CN Medio Cudeyo

Guia pas a pas per deixar el web funcionant al servidor del client.

---

## 1. Pujar el codi al servidor

Pujar tots els fitxers del projecte al servidor (Hostinger, Railway, VPS, etc.).
El `DocumentRoot` d'Apache ha d'apuntar a la carpeta **`public/`**.

---

## 2. Configurar la base de dades

1. Crear una base de dades MySQL anomenada `cn_medio_cudeyo` (o el nom que preferiu)
2. Executar el fitxer **`schema.sql`** per crear les taules i les dades inicials
3. Crear un fitxer **`.env`** a l'arrel del projecte amb les credencials:

```
DB_HOST=localhost
DB_NAME=cn_medio_cudeyo
DB_USER=el_usuari
DB_PASS=la_contrasenya
```

---

## 3. Configurar la Biblioteca (Google Drive)

### 3.1 Preparar la carpeta de Drive

1. Ves a [drive.google.com](https://drive.google.com) i crea (o tria) la carpeta que voleu usar com a biblioteca
2. Fes clic dret sobre la carpeta → **Compartir**
3. A "Accés general", selecciona **"Qualsevol persona amb l'enllaç"** → **Visualitzador**
4. Copia l'ID de la carpeta de l'URL:
   ```
   https://drive.google.com/drive/folders/AQUEST_ES_EL_ID
   ```

### 3.2 Obtenir la clau API de Google

1. Ves a [console.cloud.google.com](https://console.cloud.google.com)
2. Crea un projecte nou (p. ex. "CN Medio Cudeyo")
3. **APIs & Services → Enable APIs** → cerca **"Google Drive API"** → Activa-la
4. **APIs & Services → Credentials → Create Credentials → API Key**
5. Nom: `CN Medio Cudeyo`
6. Restriccions d'aplicació: **Direcciones IP** → afegir la IP del servidor
7. Restriccions d'API: **Restringir clave** → seleccionar **Google Drive API**
8. Fes clic a **Crear** i copia la clau

### 3.3 Afegir les dades a `config/params.php`

Obre el fitxer **`config/params.php`** i omple els dos valors:

```php
define('DRIVE_FOLDER_ID', 'POSEU_AQUI_EL_ID_DE_LA_CARPETA');
define('DRIVE_API_KEY',   'POSEU_AQUI_LA_CLAU_API');
```

---

## 4. Compte d'administrador

Les credencials per defecte de l'admin (definides al `schema.sql`) són:

```
Email:      admin@cnmediocudeyo.es
Contrasenya: Admin1234!
```

**Canviar la contrasenya just després del primer accés** des de `/admin/usuarios.php`.

---

## 5. Verificació final

| Comprovació | URL |
|-------------|-----|
| Pàgina principal | `/` |
| Login | `/login.php` |
| Panel admin | `/admin/usuarios.php` |
| Biblioteca | `/biblioteca.php` |
| Calculadoras | `/calculadoras.php` |

---

## Resum de fitxers de configuració

| Fitxer | Contingut |
|--------|-----------|
| `.env` | Credencials de base de dades |
| `config/params.php` | ID carpeta Drive + clau API |
