# Contribuir

Gracias por tomarte el tiempo de contribuir.

## Para empezar

```bash
git clone https://github.com/webrek/laravel-mongo-permission
cd laravel-mongo-permission
composer install
```

Necesitas la extensión `mongodb` de PHP y un MongoDB corriendo. Las pruebas se
conectan usando estas variables de entorno (con estos valores por defecto):

```bash
export MONGO_DB_HOST=127.0.0.1
export MONGO_DB_PORT=27017
export MONGO_DB_DATABASE=permission_test
```

## Antes de abrir un pull request

CI corre las pruebas en toda la matriz de PHP (8.2–8.5) y Laravel (12 y 13),
más análisis estático y pruebas de mutación. Localmente:

```bash
vendor/bin/phpunit                                        # pruebas
vendor/bin/phpstan analyse --memory-limit=1G              # análisis estático (nivel 5)
vendor/bin/infection --threads=max                        # pruebas de mutación
```

## Lineamientos

- Mantén los _pull requests_ enfocados; un cambio lógico por PR.
- Agrega o actualiza pruebas para cualquier cambio de comportamiento. Las
  correcciones de errores deben venir con una prueba que falle antes del arreglo.
- Que PHPStan siga pasando sin bajar el nivel.
- Actualiza `CHANGELOG.md` bajo el encabezado `Unreleased`.
