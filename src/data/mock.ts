import type { Customer, DashboardStats, Product, PurchaseAccount, Sale, SupplierPayment } from '../types/domain';

export const dashboardStats: DashboardStats = {
	sales: 18420.5,
	purchases: 8640.75,
	receivables: 12780.25,
	cash: 6540.0,
};

export const customers: Customer[] = [
	{ id: 1, name: 'Comercial El Faro', initials: 'EF', phone: '+58 412 555 0184', creditLimit: 4500, balance: 3280, daysOverdue: 12, status: 'En mora' },
	{ id: 2, name: 'Inversiones Roca', initials: 'IR', phone: '+58 414 555 0122', creditLimit: 6000, balance: 2150, daysOverdue: 0, status: 'Por vencer' },
	{ id: 3, name: 'Distribuidora Norte', initials: 'DN', phone: '+58 424 555 0109', creditLimit: 3000, balance: 980, daysOverdue: 0, status: 'Al día' },
	{ id: 4, name: 'Panadería La Espiga', initials: 'PE', phone: '+58 416 555 0166', creditLimit: 2500, balance: 1760, daysOverdue: 34, status: 'En mora' },
];

export const recentSales: Sale[] = [
	{ id: 'FAC-00482', customer: 'Comercial El Faro', date: 'Hoy, 10:42', total: 1280, status: 'Pendiente', method: 'Transferencia' },
	{ id: 'FAC-00481', customer: 'Inversiones Roca', date: 'Hoy, 09:18', total: 890.5, status: 'Pagada', method: 'Pago móvil' },
	{ id: 'FAC-00480', customer: 'Distribuidora Norte', date: 'Ayer, 16:35', total: 2340, status: 'Pagada', method: 'Efectivo' },
	{ id: 'FAC-00479', customer: 'Panadería La Espiga', date: 'Ayer, 14:07', total: 640, status: 'Vencida', method: 'Crédito' },
];

export const lowStockProducts: Product[] = [
	{ id: 'PR-01', name: 'Aceite vegetal 1L', sku: 'ACE-001', stock: 8, minimumStock: 20, price: 4.5 },
	{ id: 'PR-02', name: 'Arroz blanco 1kg', sku: 'ARR-014', stock: 12, minimumStock: 25, price: 2.2 },
	{ id: 'PR-03', name: 'Harina de trigo 1kg', sku: 'HAR-008', stock: 6, minimumStock: 15, price: 1.85 },
];

export const salesByDay = [65, 42, 78, 55, 91, 68, 84];

export const purchaseAccounts: PurchaseAccount[] = [
	{ id: 'OC-00124', supplier: 'Alimentos La Cosecha', issueDate: '06 ago 2026', dueDate: '21 ago 2026', total: 1840, paid: 840, daysOverdue: 4, status: 'Vencida' },
	{ id: 'OC-00121', supplier: 'Distribuciones Central', issueDate: '28 jul 2026', dueDate: '27 ago 2026', total: 3250.75, paid: 1250.75, daysOverdue: 0, status: 'Pendiente' },
	{ id: 'OC-00118', supplier: 'Empaques del Valle', issueDate: '15 jul 2026', dueDate: '14 ago 2026', total: 960, paid: 0, daysOverdue: 11, status: 'Vencida' },
	{ id: 'OC-00115', supplier: 'Lácteos La Pradera', issueDate: '10 jul 2026', dueDate: '09 ago 2026', total: 1250, paid: 1250, daysOverdue: 0, status: 'Pagada' },
];

export const supplierPayments: SupplierPayment[] = [
	{ id: 'PAG-00341', supplier: 'Alimentos La Cosecha', document: 'OC-00124', date: '20 ago 2026', method: 'Transferencia', amount: 840, reference: 'TRX-884201' },
	{ id: 'PAG-00340', supplier: 'Distribuciones Central', document: 'OC-00121', date: '05 ago 2026', method: 'Pago móvil', amount: 1250.75, reference: 'PM-551092' },
	{ id: 'PAG-00339', supplier: 'Lácteos La Pradera', document: 'OC-00115', date: '08 ago 2026', method: 'Efectivo', amount: 1250, reference: 'CAJA-0098' },
	{ id: 'PAG-00338', supplier: 'Alimentos La Cosecha', document: 'OC-00110', date: '18 jul 2026', method: 'Transferencia', amount: 600, reference: 'TRX-882914' },
];

export function formatCurrency(value: number): string {
	return new Intl.NumberFormat('es-VE', { style: 'currency', currency: 'USD' }).format(value);
}