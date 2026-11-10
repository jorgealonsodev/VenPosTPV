/**
 * VenPosPaymentMethods — the Spanish name of how a sale was paid, as the ticket
 * prints it (TicketData::paymentMethodLabel), for the ticket lists and details.
 * An unknown code is shown as it came.
 */
const VenPosPaymentMethods = (() => {
    const LABELS = {
        cash: 'Efectivo',
        card: 'Tarjeta',
        mixed: 'Mixto',
        transfer: 'Transferencia',
        voucher: 'Vale',
        exchange: 'Abono',
        online: 'Online',
    };

    function label(code) {
        const key = String(code == null ? '' : code);
        return LABELS[key] || key;
    }

    return { label };
})();
