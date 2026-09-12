# Propuesta comercial — Módulo de Reconocimiento Facial (MLA)

**Fecha:** 9 de septiembre de 2026
**Documento padre:** [INTEGRACION_MLA_PROVISION.md](INTEGRACION_MLA_PROVISION.md) (expediente vivo) · [INTEGRACION_PROVISION_ESPECIFICACION_Y_PRESUPUESTO.md](INTEGRACION_PROVISION_ESPECIFICACION_Y_PRESUPUESTO.md) (anexo técnico)
**Estructura comercial acordada:** $20,000 MXN de activación + $5 MXN por empleado activo al mes, adicionales a la tarifa vigente. Isra factura.

> **Este archivo tiene dos partes y no se envían juntas.**
> La **Parte A** es la propuesta para MLA — es la que se manda y la que está publicada como página.
> La **Parte B** es la nota interna para Isra: la aritmética, los supuestos y lo que no se puede recortar. **No enviar al cliente.**

---
---

# PARTE A — Propuesta para MLA

## Módulo de Reconocimiento Facial en Sintelc FlowTime

**Para:** MLA
**De:** Sintelc
**Fecha:** 9 de septiembre de 2026
**Vigencia de esta propuesta:** 30 días naturales

---

### 1. Qué resuelve

Hoy la asistencia de MLA llega a Factorial desde relojes checadores. Esta propuesta suma una segunda vía: **las cámaras Provision-ISR que MLA ya tiene instaladas registran la entrada del personal por reconocimiento facial, y ese registro viaja solo hasta Factorial**, sin contacto, sin credencial y sin captura manual.

Los marcajes de cámara entran por la misma tubería que ya opera para MLA, con las mismas reglas de negocio, y se ven en la misma pantalla de *Registros de asistencia* que su equipo ya usa. No hay un sistema nuevo que aprender.

---

### 2. Qué incluye

| | Componente | Qué hace |
|---|---|---|
| 1 | **Recepción de eventos** | Un punto de entrada dedicado y autenticado por equipo, al que las cámaras envían cada reconocimiento en el momento en que ocurre |
| 2 | **Identificación del empleado** | Traduce la persona reconocida por Provision al empleado correspondiente en Factorial |
| 3 | **Compuertas de calidad** | Descarta reconocimientos por debajo del umbral de confianza, sin prueba de vida, o de personas fuera de la lista autorizada. Un evento descartado queda registrado con su motivo, a la vista, nunca se pierde en silencio |
| 4 | **Modo sólo-entradas con cierre automático** | MLA confirmó que las salidas no cuentan para su reporte. El turno se cierra solo con las horas planeadas que ya están cargadas en Factorial. **Este mecanismo ya está construido y operando en producción para MLA desde el 17 de agosto** |
| 5 | **Anti-duplicados entre cámara y reloj** | Si un empleado pasa por la cámara y también marca en el reloj, se registra una sola entrada. Sin esto, Factorial recibe marcajes duplicados y la nómina se ensucia |
| 6 | **Monitoreo y alertas** | Latido de cada cámara y aviso cuando una deja de reportar. Es la diferencia entre enterarse el mismo día y enterarse en la quincena |
| 7 | **Puesta en marcha asistida** | Configuración, calibración de umbrales con datos reales, piloto acompañado en una sede y capacitación a su equipo |

---

### 3. Privacidad: FlowTime no almacena datos faciales

Es una decisión de diseño y conviene entenderla, porque cambia quién carga con qué.

El tratamiento biométrico —capturar el rostro, generar la plantilla y compararla contra el padrón— **ocurre íntegramente dentro del equipo Provision, en las instalaciones de MLA.** Nunca sale de ahí.

Lo único que cruza a FlowTime es *"la persona con identificador 1585278715 fue reconocida a las 07:14 en la cámara de la entrada principal"*. Un identificador y una hora, del mismo tipo que el PIN de un reloj checador.

En consecuencia: **no hay imágenes, plantillas ni vectores faciales almacenados en nuestros servidores**, ni siquiera de forma temporal. Las imágenes que acompañan al evento se descartan antes de cualquier escritura a disco. No hay nada que cifrar, nada que purgar y nada que exponer ante un incidente.

El padrón facial sigue siendo de MLA, y con él las obligaciones de la LFPDPPP sobre datos sensibles —aviso de privacidad específico y consentimiento expreso del personal. Lo señalamos temprano porque suele ser el paso que más tiempo toma y no depende de nada técnico.

---

### 4. Requisitos a cargo de MLA

Estos puntos son condición para que el módulo funcione. No son opcionales y no están incluidos en la inversión de esta propuesta.

**4.1 · NVR Provision con Reconocimiento Facial** ⚠️ *el más importante*

Las cuatro cámaras instaladas son **I4-340IPEN-MVF-V5** de la serie Eye-Sight. Verificado en el manual del fabricante y en su catálogo público: **esas cámaras hacen detección facial, no reconocimiento.** Saben que hay un rostro; no saben de quién es.

El reconocimiento —el que produce la identidad de la persona— lo realiza un **NVR Provision con función de Face Recognition**, que administra las fichas del padrón. Sin ese equipo no hay identidad, y sin identidad no hay marcaje que registrar, por mucho software que se escriba.

El equipo, su instalación y su configuración corren por cuenta de MLA o de su proveedor Provision. Necesitamos confirmar **modelo y versión de firmware** antes de arrancar.

**4.2 · Padrón facial cargado**

Las aproximadamente **545 personas** deben estar dadas de alta con su rostro en el NVR. El enrolamiento inicial es trabajo operativo de MLA. Como referencia: a dos minutos por persona son cerca de 18 horas de trabajo. Si prefieren que lo hagamos nosotros, se cotiza aparte.

**4.3 · Un solo identificador por persona**

El código de cada empleado en Provision debe ser **el mismo** que ya tiene en FlowTime. Si Provision carga una numeración propia, hay que mantener una tabla de equivalencias con alta y baja permanentes del lado de MLA. Es evitable si se define desde el principio.

**4.4 · Salida a internet desde el NVR**

El equipo necesita poder enviar los eventos hacia `app.sintelcft.dev`. No requerimos entrar a la red de MLA: la comunicación es siempre de salida.

**4.5 · Decisión sobre el reloj checador**

Hay que definir si en cada sede la cámara **sustituye** al reloj o **convive** con él. Ambas son válidas, pero cambian la configuración y hay que decidirlo antes del arranque.

---

### 5. Fuera de alcance

Se enuncia para que no haya sorpresas. Cada punto es cotizable por separado.

- Hardware, cableado, licencias de Provision e instalación física.
- Enrolamiento asistido de rostros del personal.
- **Registro de salidas por cámara.** Un reconocimiento facial no distingue si la persona entra o sale; requeriría una cámara dedicada por dirección en cada acceso. Como las salidas no cuentan para el reporte de MLA, no forma parte de esta propuesta.
- Alta y baja de rostros desde FlowTime hacia las cámaras.
- Recuperación histórica de eventos anteriores a la puesta en marcha.

---

### 6. Inversión

| Concepto | Importe |
|---|---|
| **Activación del módulo** (única vez) | **$20,000 MXN** |
| **Suplemento mensual**, adicional a la tarifa vigente de FlowTime | **$5 MXN por empleado activo al mes** |

Con la plantilla actual de 545 empleados, el suplemento representa **$2,725 MXN al mes**.

El suplemento cubre monitoreo, alertas, soporte del módulo y el mantenimiento ante cambios de la API de Provision, que es un tercero que actualiza su firmware sin avisarnos.

**Condiciones:**

- Precios en pesos mexicanos, antes de IVA.
- **Permanencia mínima: 36 meses** sobre el suplemento mensual. El precio de activación está calculado sobre ese horizonte.
- El suplemento se factura sobre la plantilla activa del mes.
- Alta de cámaras o sedes adicionales: cotizable, no incluido.

---

### 7. Cómo arranca

| Etapa | Qué pasa | Duración |
|---|---|---|
| **1 · Validación técnica** | Con el NVR ya instalado, verificamos en sitio que entregue la identidad de la persona en tiempo real y capturamos los eventos reales. Cerramos configuración y umbrales | 1 – 2 semanas |
| **2 · Construcción** | Recepción de eventos, identificación, compuertas de calidad, anti-duplicados y monitoreo | 4 – 6 semanas |
| **3 · Piloto** | Una sede en operación real, en paralelo con el mecanismo actual, afinando umbrales con datos | 2 semanas |
| **4 · Liberación** | Resto de las sedes y capacitación | 1 semana |

**Total estimado: 8 a 11 semanas a partir de que el NVR esté instalado y el padrón cargado.** El reloj no arranca con la firma, arranca con el hardware listo.

> **Cláusula de validación técnica.** La etapa 1 verifica un supuesto que hoy depende del fabricante: que el NVR entregue la identidad de la persona por envío automático. Si esa verificación resulta negativa, se lo informamos con la evidencia, el proyecto se detiene ahí y **se reintegra la activación menos $6,000 MXN correspondientes al trabajo de validación**. No cobramos por construir sobre un supuesto que no se sostuvo.

---

### 8. Por qué esta cifra

Conviene decirlo con transparencia: el desarrollo completo de un módulo de esta naturaleza es un proyecto de seis cifras. Esta propuesta puede ofrecerse a $20,000 de entrada por dos razones concretas y verificables:

1. **La mitad del trabajo ya está construida y corriendo para MLA.** El registro de sólo entradas, el cierre automático de turnos, la sincronización con Factorial, la pantalla de consulta y el manejo de errores están en producción desde agosto. La cámara se conecta a esa maquinaria, no la reemplaza.
2. **El módulo se financia con la recurrencia, no con la entrada.** Por eso el suplemento mensual y la permanencia mínima son parte inseparable de la oferta.

---

### 9. Aceptación

Para arrancar necesitamos, en este orden:

1. Confirmación de modelo y firmware del NVR con Face Recognition.
2. Decisión sobre reemplazo o convivencia con el reloj checador en cada sede.
3. Aceptación por escrito de esta propuesta.

---
---

# PARTE B — Nota interna para Isra

> **No enviar al cliente.** Aquí está la aritmética que sostiene la Parte A y lo que hay que cuidar al negociarla.

## B.1 · La aritmética, con el número real de plantilla

Consultado en producción el 9 de septiembre de 2026: **Outlandish (`client_id 4`) tiene 545 empleados en Factorial** y 581 códigos mapeados en el biométrico.

| | $4 por empleado | **$5 por empleado** |
|---|---|---|
| Mensual (545 empleados) | $2,180 | **$2,725** |
| 12 meses | $26,160 | $32,700 |
| **24 meses + activación** | $72,320 | **$85,400** |
| **36 meses + activación** | $98,480 | **$118,100** |

El esfuerzo documentado para el núcleo de la integración es de **98 a 144 horas** de desarrollo, equivalentes a $78,000 – $115,000 a la tarifa de referencia de $800/hora (§10 y §11 del anexo técnico).

**Punto de equilibrio, contra ese esfuerzo:**

| Escenario | A $4 por empleado | A $5 por empleado |
|---|---|---|
| Si el desarrollo sale por lo bajo (98 h) | mes **27** | mes **21** |
| Si sale por lo alto (144 h) | mes **44** | mes **35** |

**Ésa es toda la discusión.** A $4 el proyecto puede tardar casi cuatro años en pagarse; a $5 se paga dentro del horizonte de un contrato de tres años. La diferencia entre $4 y $5 a 36 meses son **$19,620** — prácticamente la activación completa, otra vez.

**Recomendación: $5, y permanencia mínima de 36 meses.** Si MLA empuja el precio hacia abajo, la palanca correcta no es bajar el suplemento, es subir la activación.

## B.2 · Lo que hay que confirmar antes de mandar la propuesta

Tres supuestos. Los dos primeros cambian los números; el tercero cambia el alcance.

1. **¿MLA es Outlandish (`client_id 4`)?** Sigue sin confirmarse desde el 31 de agosto. Toda la aritmética de arriba y el argumento de "la mitad ya está construida" dependen de que sea el mismo cliente. Si es otro, hay que rehacer la cuenta y el descuento implícito ya no aplica.
2. **¿Qué NVR tienen, y tienen alguno?** Si MLA supuso que las cámaras solas hacían reconocimiento, la conversación empieza con una compra de hardware que no esperaban. Conviene que ese golpe lo reciban de nosotros con la evidencia del manual enfrente, y no a mitad del proyecto.
3. **¿La cámara reemplaza al reloj o convive?** Si conviven, el anti-duplicados es obligatorio y no se recorta a ningún precio: sin él llegan entradas duplicadas a Factorial y se corrompe la nómina de 545 personas.

## B.3 · La trampa del enrolamiento

Dar de alta 545 rostros son unas 18 horas de trabajo operativo. A la tarifa de referencia de $35 por empleado eso vale **$19,075** — casi exactamente la activación entera.

Si en la negociación se acepta "nosotros les ayudamos con las altas" como cortesía, **la activación se convierte en cero.** Tiene que quedar por escrito que el enrolamiento es de MLA, o cotizarse aparte.

## B.4 · Qué no recortar

- El **anti-duplicados**, si hay convivencia con el reloj.
- Las **compuertas de calidad**. Un falso positivo de reconocimiento facial es un dato de nómina atribuido a la persona equivocada.
- La **alerta de cámara caída**. El 31 de agosto un equipo de este mismo cliente dejó de reportar a las 08:02 sin un solo error en el log y nadie se enteró. Con cámaras el escenario es idéntico y el costo de no enterarse, también.

## B.5 · Riesgo que asumimos con esta estructura

Cobrar $20,000 por adelantado y financiar el resto con la mensualidad significa que **el riesgo de que MLA se vaya antes del mes 21 lo cargamos nosotros.** La cláusula de permanencia mínima no es letra chica, es la pieza que sostiene el modelo. Sin ella firmada, la propuesta pierde dinero en cualquier escenario de salida temprana.

La cláusula de validación técnica de la §7 cubre el otro riesgo: que el NVR no entregue identidad por envío automático. Es la incógnita que el anexo técnico marca como determinante de la arquitectura, y es la razón de reservar $6,000 de los $20,000 contra el trabajo de validación.

---

## Bitácora

- **2026-09-09** — Documento creado a partir de la estructura comercial acordada por Daniel ($20,000 de activación + $4–5 por empleado al mes), con Isra como responsable de la facturación. Sustituye, para el caso MLA, el escenario de "entrada baja" de §11.5 del anexo técnico. Plantilla verificada en producción: 545 empleados. Se recomienda $5 y permanencia de 36 meses sobre la base del punto de equilibrio calculado en §B.1.
