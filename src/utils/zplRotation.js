/**
 * La rotazione come la fa davvero la stampante.
 *
 * Lo ZPL conosce quattro orientamenti (N/R/I/B) e per tutti tiene fermo
 * l'angolo in alto a sinistra del riquadro su ^FO: il contenuto ruota "sul
 * posto" e per 90°/270° larghezza e altezza si scambiano. Un semplice
 * rotate() CSS attorno all'angolo manda invece il contenuto sopra o a
 * sinistra dell'origine: l'anteprima mostrava il testo in un punto e la
 * stampante lo metteva in un altro (spesso fuori dall'etichetta).
 *
 * Le percentuali nelle translate si riferiscono al riquadro non ruotato
 * dell'elemento, quindi la compensazione vale per qualunque dimensione.
 * Il server fa la stessa normalizzazione in ElementRotation.php: tutto
 * cio' che non e' esattamente 90/180/270 stampa dritto.
 */

const ROTATION_TRANSFORMS = {
  90: 'rotate(90deg) translateY(-100%)',
  180: 'rotate(180deg) translate(-100%, -100%)',
  270: 'rotate(270deg) translateX(-100%)',
}

export function zplRotationDegrees(rotation) {
  const value = Number(rotation)

  if (!Number.isFinite(value)) return 0

  const angle = Math.trunc(value) % 360

  return angle === 90 || angle === 180 || angle === 270 ? angle : 0
}

export function zplRotationTransform(rotation) {
  return ROTATION_TRANSFORMS[zplRotationDegrees(rotation)] ?? ''
}
