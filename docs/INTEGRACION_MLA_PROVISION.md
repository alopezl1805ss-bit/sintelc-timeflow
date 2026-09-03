# Integración MLA ↔ Provision ↔ FlowTime ↔ Factorial

**Documento vivo.** Se actualiza conforme lleguen los entregables. Última actualización: **31 ago 2026**, tras la reunión con Provision.

**Flujo objetivo:** cámara Provision → API/export de Provision → **FlowTime** → Factorial.

> **Cómo usar este archivo:** conforme lleguen SDKs, documentación, archivos de ejemplo o correos, se pegan o se referencian en la **Bitácora** (final del documento) y de ahí se integran a las secciones correspondientes. La idea es que este sea el único lugar donde se busque cualquier cosa de esta integración.

---

## 1. Estado del expediente

### Entregables pendientes de Provision

| # | Entregable | Responsable | Qué desbloquea de nuestro lado | Prioridad |
|---|---|---|---|---|
| E1 | **Documentación de las APIs y variables utilizadas** | Rafa | Todo el diseño de la ingesta. Sin esto no hay estimación cerrada | 🔴 Crítico |
| E2 | **Archivo de export de ejemplo con encabezados** *(no está en la minuta — hay que pedirlo)* | Rafa | El contrato de datos. Es lo que convierte "la columna G" en una especificación | 🔴 Crítico |
| E3 | **Confirmación de webhooks** | Provision | Define la arquitectura completa: tiempo real vs. consulta periódica | 🔴 Crítico |
| E4 | **SDKs de Provision** | Rafa (≤ 1 día hábil) | Referencia. **No planeamos usarlos** (ver §4.1), pero sirven para entender el modelo de datos | 🟡 Medio |
| E5 | **Matriz de modelos de cámara** — qué aplica a qué | Rafa + ing. Luis | Si hay variación por modelo, se resuelve con perfiles, no con excepciones en el código | 🟡 Medio |
| E6 | **Modelo de versionamiento de APIs** — frecuencia y fechas de obsolescencia | Rafa | Política de mantenimiento y alertas. Ver riesgo R5 | 🟡 Medio |
| E7 | **Endpoints de estatus de registro biométrico por usuario** | Provision | Función de valor para el cliente: detectar empleados sin rostro registrado | 🟢 Deseable |
| E8 | **Contacto del equipo de desarrollo** (Israel/Asia) | Provision | Resolver dudas de campo sin adivinar | 🟡 Medio |
| E9 | **Minuta formal de la reunión** | — | Registro | 🟢 — |

### Lo que ya está resuelto y no hay que construir

Ver §3.1 — es la mejor noticia de la reunión y conviene tenerla clara antes de cotizar.

---

## 2. Lo que se confirmó, y qué significa para FlowTime

| Lo que dijeron en la reunión | Qué significa técnicamente |
|---|---|
| **Solo se necesitan entradas, no salidas** | Es exactamente el modo `checkin_only`, **que ya existe y corre en producción desde el 17 de agosto**. No es desarrollo nuevo. Ver §3.1 |
| **No hay turnos nocturnos en Guadalajara** | Elimina el único caso donde nuestra búsqueda de turnos se extiende al día anterior. Simplifica real, no solo declarativamente |
| **Campos a consumir: nombre, fecha, earliest time, check-in time** | Define el contrato mínimo. Pero abre una decisión de arquitectura sin cerrar — ver §4.2 |
| **Riesgo de duplicados si el colaborador pasa varias veces frente a la cámara** | Confirmado como riesgo real. La mitigación propuesta (usar el primer registro del día) es la correcta |
| **La columna G del export podría manejar la agrupación nativa** | Sugiere que sí hay agrupación del lado de Provision. **Hay que confirmarlo con el archivo de ejemplo**, no con la posición de una columna — ver riesgo R2 |
| **Sí es posible asignar IDs únicos por usuario, pero el campo puede venir vacío** | Resuelve el eje del mapeo, con una condición dura: ver §4.3 y riesgo R1 |
| **Identificar por nombre es riesgo alto** | Coincidimos. Regla propuesta: **nunca hacer fallback a nombre** — ver §4.3 |
| **Las cámaras son CCTV con reconocimiento facial, no biométricos dedicados** | Los biométricos dedicados no están disponibles en México todavía. Confirma que este es el camino, no un interino |
| **Equipo de desarrollo en Israel/Asia, 9–14 h de diferencia** | Cada ida y vuelta técnica cuesta un día. Refuerza pedir documentación completa por adelantado en vez de resolver en llamadas |
| **Hubo actualizaciones recientes de API; poco probable otro cambio pronto** | Tranquiliza a corto plazo, no sustituye la política escrita (E6) |

---

## 3. El hallazgo más importante de la reunión

### 3.1 El cliente ya está vivo en FlowTime, y ya es `checkin_only`

La minuta dice que Joaquín representa a **MyNekMan / Outlandish**, "que ya tiene vinculados dispositivos y empleados en la plataforma".

> ⚠️ **Confirmar la equivalencia:** ¿MLA, MyNekMan y Outlandish son la misma entidad, o entidades relacionadas del mismo grupo? De la respuesta depende buena parte de lo que sigue.

**Si es el mismo Outlandish que ya opera en FlowTime, esto es lo que ya está resuelto:**

- Es cliente activo con **`checkin_only = true`** y **cierre automático de turnos activado** desde el 17 de agosto.
- El requisito estrella de la reunión —"solo entradas, no salidas"— **ya está construido, probado y corriendo en producción para este cliente exacto**. Cuando se activó, cerró 53 turnos limpiamente, sin una sola falla.
- Ya tiene empleados mapeados y dispositivos vinculados: la infraestructura de identidades ya existe.
- Ya tiene conexión OAuth a Factorial funcionando.

**Cómo se usa esto comercialmente:** no estamos proponiendo construir un modo "solo entradas" para MLA. Estamos proponiendo **conectar una fuente nueva a un flujo que ya lleva dos semanas funcionando para ellos**. Es un argumento de riesgo bajo, y es verdad.

### 3.2 Pero hereda los pendientes conocidos de ese cliente

Antes de sumar una fuente nueva conviene cerrar lo que ya está abierto con ellos:

- **Backfill de 40 registros pendiente de aprobación** (15–17 de agosto), por la falla intermitente del reloj. Toca datos de nómina, por eso está detenido. Si van a convivir cámara y reloj, hay que cerrarlo antes de complicar el panorama.
- **Peculiaridad de datos ya documentada:** su lógica de fallback venía deslizando la fecha de inicio del mismo turno día tras día, en vez de acumular turnos abiertos separados. Hay que verificar cómo se comporta eso cuando las entradas empiecen a llegar de una fuente distinta.
- **Es el cliente que detonó el límite de tamaño de petición** con sus ~508 empleados. Ya está resuelto con troceo, pero es el volumen de referencia para dimensionar.

---

## 4. Decisiones de arquitectura abiertas

### 4.1 Vía de integración: SFTP, API o SDK

Postura vigente, sin cambios tras la reunión: **API o export por archivo; el SDK no**. Un SDK de CCTV suele ser una librería nativa que obliga a instalar y mantener un agente dentro de la red del cliente, fuera de nuestra aplicación, atado a su ciclo de versiones.

**Lo que decide entre API y archivo es E3 (webhooks):**

- **Si hay webhook** → tiempo real, es lo mejor. **Con una condición no negociable:** ese endpoint nuevo **tiene que autenticarse**. Hoy los endpoints de los relojes no tienen autenticación porque el protocolo del fabricante no lo permite; **eso no puede repetirse en un endpoint que diseñamos nosotros**. Secreto compartido o firma en cada evento, desde el día uno.
- **Si no hay webhook** → consulta periódica a su API, o lectura de export por SFTP. Ambas funcionan; la frecuencia define la latencia.

### 4.2 "Earliest time": tiempo real o lote diario

Esta es la decisión que la minuta deja abierta y que más afecta al comportamiento.

Usar el primer registro del día como marcaje es correcto. Pero hay dos formas de implementarlo, y **no son equivalentes**:

- **Tiempo real con retén diario (recomendado).** Procesamos cada detección conforme llega; la primera de cada empleado en el día se convierte en su entrada, y el resto del día se suprime. El resultado es idéntico a "earliest time", pero la entrada llega a Factorial **cuando ocurre**.
- **Consumir el campo `earliest time` de un export diario.** El marcaje llega **al día siguiente**. Esto arrastra el calendario completo del cierre automático de turnos, que calcula la hora de cierre a partir de la entrada. Funciona, pero es frágil y le da al cliente una vista con un día de retraso.

**Recomendación:** tiempo real con retén, salvo que E1/E3 demuestren que su sistema no lo permite. Si terminamos en lote diario, hay que revisar explícitamente la interacción con el cierre automático antes de activarlo.

### 4.3 Identificador de empleado

**Regla propuesta, para cerrarla con Provision por escrito:**

1. **El ID único es obligatorio.** Un evento sin ID no se convierte en marcaje: entra como pendiente de identificación (el sistema ya tiene ese estado y esa pantalla) para que alguien lo resuelva. **Nunca se hace fallback a nombre.**
2. **El ID que se cargue en Provision debe ser el mismo código que el empleado ya tiene en FlowTime.** Si Outlandish ya tiene sus empleados mapeados por su código actual, y Provision carga ese mismo código como ID único, **el trabajo de mapeo es cero**. Si cargan una numeración propia, hay que mantener una tabla de equivalencias — se puede, pero es alta y mantenimiento permanente para el cliente.
3. **Hay que medir cuántos usuarios tienen el campo vacío hoy**, antes de arrancar. Es trabajo de captura del lado de MLA y conviene que lo sepan con número, no como sorpresa.

### 4.4 ¿Las cámaras reemplazan al reloj, o conviven?

**No se tocó en la reunión y es crítico.**

Si conviven, el mismo empleado puede generar una entrada por el reloj y otra por la cámara el mismo día. Nuestra defensa contra duplicados funciona por combinación de *fuente + empleado + hora exacta*: **dos fuentes distintas son dos combinaciones distintas, así que el duplicado pasa el filtro.** El síntoma sería un segundo registro de entrada rechazado por Factorial con el error de turno abierto — recuperable, pero ruidoso y confuso para el cliente.

**Hay que decidir una de tres:** una sola fuente por sede; una regla de precedencia por empleado y día (la primera entrada de cualquier fuente gana); o migración limpia con fecha de corte. **La segunda es la más robusta y hay que construirla explícitamente — hoy no existe.**

---

## 5. Riesgos

| # | Riesgo | Impacto | Mitigación |
|---|---|---|---|
| **R1** | **IDs únicos vacíos** en parte del padrón de Provision | Empleados cuyos marcajes no se pueden atribuir | Regla dura de §4.3 + censo de campos vacíos antes de arrancar |
| **R2** | **La agrupación nativa se identificó por posición de columna** ("la columna G"), no por especificación | Un cambio de formato de su lado rompe la lectura en silencio | Pedir el archivo de ejemplo (E2) y trabajar contra **nombres** de columna, nunca posiciones |
| **R3** | **Falso positivo del reconocimiento facial** | Un marcaje atribuido a la persona equivocada — es un dato de nómina incorrecto | Preguntar por el umbral de confianza y si viene el score en el evento. No está en la minuta |
| **R4** | **Cámaras y reloj conviviendo** sin regla de precedencia | Entradas duplicadas por empleado/día | §4.4 |
| **R5** | **Obsolescencia de versión de API sin aviso** | La integración se rompe sin que nadie lo note | Fijar versión explícita en el código —nunca "la última", igual que hacemos con Factorial— + alerta cuando la fuente deje de entregar eventos |
| **R6** | **Zona horaria del timestamp** | Marcajes corridos 6 h o más | Confirmar si el evento viene en hora local o UTC. Guadalajara es UTC−6 fijo (Jalisco no cambia horario), pero su equipo está en Israel/Asia y esos sistemas suelen entregar UTC |
| **R7** | **Pérdida de eventos en corte de red o energía** | Días de asistencia perdidos | Confirmar si se acumulan y se recuperan, y si al recuperarse traen su hora real. Ya sabemos reconstruir lotes atrasados, pero solo si traen su hora real |
| **R8** | **Coordinación con 9–14 h de diferencia horaria** | Cada duda técnica cuesta un día | Documentación completa por adelantado; llamadas solo para lo que no se pueda resolver por escrito |

---

## 6. Preguntas para el correo de seguimiento

Las que no se resolvieron en la llamada. Listas para copiar.

**Sobre los datos**
1. ¿Nos pueden compartir **un archivo de export de ejemplo**, con encabezados y datos de prueba anonimizados? *(E2 — es lo que más acelera todo)*
2. ¿El timestamp de cada evento viene en **hora local de Guadalajara o en UTC**?
3. ¿El evento incluye un **nivel de confianza** del reconocimiento? ¿Hay umbral configurable, y qué hace el sistema con una coincidencia dudosa?
4. ¿El sistema reporta también **personas desconocidas o visitantes**? Necesitamos poder filtrarlos.
5. ¿Cuántos usuarios del padrón actual **tienen el ID único vacío**?

**Sobre el comportamiento**
6. ¿Qué pasa con los eventos durante un **corte de red o de energía**? ¿Se acumulan y se recuperan, y con qué hora?
7. ¿Cuánto tiempo **conservan los eventos** de su lado, por si hay que reprocesar?
8. ¿Quién **da de alta el rostro** de un empleado nuevo, y existe API para hacerlo por sistema?

**Sobre la operación**
9. ¿Cuántas **cámaras** hay en Guadalajara, en qué accesos, y cuántos empleados pasan por ellas?
10. ¿El **SDK o la API tienen costo de licencia** — por cámara, por grabador, anual?
11. ¿Hay **ambiente de pruebas** con datos propios? *(No vamos a probar contra el sistema en vivo del cliente.)*

**Para MLA, no para Provision**
12. ¿Las cámaras **reemplazan** al reloj actual o **conviven** con él? *(§4.4)*
13. ¿MLA, MyNekMan y Outlandish son la misma entidad? *(§3.1)*

---

## 7. Cómo encaja en el plan de la auditoría

**Esto no cambia las prioridades del plan — las hace más urgentes.**

- **B1 (respaldar el código en el repositorio) sube de urgencia.** Va a entrar código nuevo de una integración con un tercero. Escribir eso encima de una base que no está versionada es multiplicar el problema, no sumarlo. **Sigue siendo lo primero.**
- **Alertas operativas (punto 5 del backlog) dejan de ser deseables.** Con una fuente externa de por medio y riesgo de obsolescencia de API (R5), "la integración se rompió y nadie se enteró" pasa de hipótesis a escenario probable. Sube de prioridad.
- **El backfill pendiente de Outlandish (B6) es ahora bloqueante de la fase MLA**, no un pendiente suelto: es el mismo cliente.
- **La zona horaria fija (punto 6)** no explota aquí — Guadalajara coincide con el −6 configurado — pero R6 es el mismo problema por el otro extremo.
- **Si hay webhook, aparece un requisito nuevo que el plan no tenía:** autenticación de entrada. Ver §4.1.

### Bloque nuevo: Fase MLA

| Paso | Depende de | Notas |
|---|---|---|
| Definir contrato de datos | E1, E2 | Sin esto no hay estimación cerrada |
| Decidir tiempo real vs. lote | E3 | §4.2 |
| Decidir reemplazo vs. convivencia | MLA | §4.4 — respuesta del cliente, no de Provision |
| Lector de la fuente + intérprete | contrato definido | Nuevo |
| Retén de primera detección del día | decisión §4.2 | Nuevo |
| Regla de precedencia entre fuentes | decisión §4.4 | Nuevo, **solo si conviven** |
| Autenticación del endpoint de entrada | E3 | Nuevo, **solo si hay webhook** |
| Pantalla de empleados sin rostro registrado | E7 | Opcional, buen valor por poco costo |
| Mapeo persona ↔ empleado | — | **Se reusa** |
| Anti-duplicados, orden cronológico, envío a Factorial, 4 niveles de recuperación, cierre automático de turnos | — | **Se reusa completo, sin cambios** |

---

## 8. Bitácora

Registro cronológico. Aquí se van pegando o referenciando los entregables conforme llegan.

### 2026-08-31 — Reunión con Provision
Participantes mencionados: Rafa (Provision), ing. Luis (modelos de cámara), Joaquín (MyNekMan/Outlandish), Alex Vera, Daniel.
Resumen y acuerdos: integrados en las secciones 2 a 4 de este documento.
Pendientes generados: E1 a E9.

### _(pendiente)_ — SDKs de Provision
> Al recibirlos, anotar aquí qué llegó y si aporta algo al modelo de datos. Recordar: los SDKs son referencia, no la vía elegida.

### _(pendiente)_ — Documentación de APIs y variables
> Al recibirla, extraer a §4 el contrato de datos real y cerrar las decisiones abiertas.

### _(pendiente)_ — Matriz de modelos de cámara
> Si hay variación de capacidades por modelo, se resuelve con perfiles de protocolo, siguiendo la regla que ya está escrita para los relojes: nada de condicionales por modelo dentro de los controladores.

### _(pendiente)_ — Modelo de versionamiento de APIs
> Al recibirlo, convertirlo en política escrita: qué versión fijamos, con cuánta anticipación avisan una obsolescencia, y qué alerta nos avisa a nosotros.

### _(pendiente)_ — Confirmación de webhooks
> Cierra §4.1 y determina si hace falta construir autenticación de entrada.

### _(pendiente)_ — Endpoints de estatus biométrico
> Cierra E7 y define si se construye la pantalla de empleados sin rostro registrado.

### _(pendiente)_ — Contacto del equipo de desarrollo de Provision
> Anotar nombre, zona horaria y canal acordado.
