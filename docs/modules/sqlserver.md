# Módulo SqlServer

Ajusta cada conexión `sqlsrv` al abrirse con `SET DATEFORMAT ymd`.

Los logins compartidos de SQL Server de la UBO tienen idioma español, que lee las fechas como `dmy`.
Laravel envía las fechas como `Y-m-d H:i:s`, así que sin este ajuste un `2026-10-03` se lee como el
10 de marzo, o falla si el día pasa de 12.

**Viene apagado**: cambia el comportamiento de las conexiones de la aplicación, así que cada una lo
enciende a propósito.

## 1. Activarlo

```dotenv
UBO_COMMON_SQLSERVER=true
```

Desde ese momento:

- Cada conexión `sqlsrv` que se abre ejecuta `SET DATEFORMAT ymd` (evento `ConnectionEstablished`).
- Las conexiones que ya estaban abiertas cuando arrancó el módulo también se ajustan.
- Las conexiones de otros drivers (sqlite, mysql...) no se tocan.

Se usa `unprepared()` porque un `SET` dentro de una sentencia preparada solo dura ese batch.

## 2. Otro formato

```dotenv
UBO_SQLSERVER_DATE_FORMAT=ymd   # mdy, dmy, ymd, ydm, myd o dym
```

Un valor fuera de esa lista lanza `InvalidArgumentException` al abrir la conexión, en vez de enviarse a
SQL Server.

## 3. Migrar desde el AppServiceProvider

API-Auth y API-Maestros tienen hoy el mismo listener en su `AppServiceProvider`. Al activar el módulo
se borra ese bloque (el `Event::listen(... ConnectionEstablished ...)` y, en API-Auth, el recorrido
de `DB::getConnections()`). Si quedan los dos, el `SET` se ejecuta dos veces, sin otro efecto.
