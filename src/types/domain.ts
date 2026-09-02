export type PaymentMethod = 'Efectivo' | 'Transferencia' | 'Pago móvil' | 'Divisas' | 'Crédito';
export type PaymentStatus = 'Pagada' | 'Pendiente' | 'Vencida';

export interface Customer {
	id: string | number;
	name: string;
	initials: string;
	phone: string;
	creditLimit: number;
	balance: number;
	daysOverdue: number;
	status: 'Al día' | 'Por vencer' | 'En mora';
}

export interface Sale {
	id: string;
	customer: string;
	date: string;
	total: number;
	status: PaymentStatus;
	method: PaymentMethod;
}

export interface Product {
	id: string;
	name: string;
	sku: string;
	stock: number;
	minimumStock: number;
	price: number;
}

export interface DashboardStats {
	sales: number;
	purchases: number;
	receivables: number;
	cash: number;
}

export interface PurchaseAccount {
	id: string;
	supplier: string;
	issueDate: string;
	dueDate: string;
	total: number;
	paid: number;
	daysOverdue: number;
	status: 'Pagada' | 'Pendiente' | 'Vencida';
}

export interface SupplierPayment {
	id: string;
	supplier: string;
	document: string;
	date: string;
	method: 'Transferencia' | 'Efectivo' | 'Pago móvil';
	amount: number;
	reference: string;
}