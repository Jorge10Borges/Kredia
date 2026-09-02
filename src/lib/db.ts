import mysql from 'mysql2/promise';
import type { Customer, PurchaseAccount } from '../types/domain';

const connectionConfig = {
	host: import.meta.env.DB_HOST ?? '127.0.0.1',
	port: Number(import.meta.env.DB_PORT ?? 3306),
	database: import.meta.env.DB_NAME ?? 'kredia',
	user: import.meta.env.DB_USER ?? 'root',
	password: import.meta.env.DB_PASSWORD ?? '',
	charset: import.meta.env.DB_CHARSET ?? 'utf8mb4',
};

export interface CustomerKpis {
	activeCustomers: number;
	activeCredits: number;
	totalCustomers: number;
}

export async function getPurchaseAccounts(): Promise<PurchaseAccount[]> {
	const connection = await mysql.createConnection(connectionConfig);

	try {
		const [rows] = await connection.query<mysql.RowDataPacket[]>(`
			SELECT
				id,
				numero_documento,
				proveedor,
				fecha_emision,
				fecha_vencimiento,
				total,
				monto_pagado,
				dias_mora,
				estado_pago
			FROM vista_cuentas_por_pagar
			ORDER BY fecha_emision DESC, id DESC
		`);

		return rows.map((row) => ({
			id: String(row.numero_documento),
			supplier: String(row.proveedor),
			issueDate: formatDate(row.fecha_emision),
			dueDate: formatDate(row.fecha_vencimiento),
			total: Number(row.total),
			paid: Number(row.monto_pagado),
			daysOverdue: Number(row.dias_mora),
			status: formatStatus(String(row.estado_pago)),
		}));
	} finally {
		await connection.end();
	}
}

export async function getCustomers(): Promise<Customer[]> {
	const connection = await mysql.createConnection(connectionConfig);

	try {
		const [rows] = await connection.query<mysql.RowDataPacket[]>(`
			SELECT
				c.id,
				c.nombre,
				c.telefono_principal,
				c.estado,
				COALESCE(cc.limite_credito, 0) AS limite_credito,
				COALESCE(cc.saldo_pendiente, 0) AS saldo_pendiente,
				COALESCE(cc.dias_mora, 0) AS dias_mora,
				COALESCE(cc.estado_credito, 'activo') AS estado_credito
			FROM clientes c
			LEFT JOIN clientes_creditos cc ON cc.cliente_id = c.id
			ORDER BY c.nombre ASC
		`);

		return rows.map((row) => {
			const name = String(row.nombre);
			const balance = Number(row.saldo_pendiente);
			const daysOverdue = Number(row.dias_mora);

			return {
				id: String(row.id),
				name,
				initials: name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase(),
				phone: String(row.telefono_principal),
				creditLimit: Number(row.limite_credito),
				balance,
				daysOverdue,
				status: formatCustomerStatus(String(row.estado), String(row.estado_credito), balance, daysOverdue),
			};
		});
	} finally {
		await connection.end();
	}
}

export async function getCustomerKpis(): Promise<CustomerKpis> {
	const connection = await mysql.createConnection(connectionConfig);

	try {
		const [rows] = await connection.query<mysql.RowDataPacket[]>(`
			SELECT
				COUNT(*) AS total_customers,
				SUM(c.estado = 'activo') AS active_customers,
				SUM(cc.estado_credito = 'activo') AS active_credits
			FROM clientes c
			LEFT JOIN clientes_creditos cc ON cc.cliente_id = c.id
		`);
		const row = rows[0];

		return {
			activeCustomers: Number(row.active_customers ?? 0),
			activeCredits: Number(row.active_credits ?? 0),
			totalCustomers: Number(row.total_customers ?? 0),
		};
	} finally {
		await connection.end();
	}
}

function formatDate(value: Date | string): string {
	const date = new Date(value);
	return new Intl.DateTimeFormat('es-VE', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(date).replace('.', '');
}

function formatStatus(value: string): PurchaseAccount['status'] {
	if (value === 'pagada') return 'Pagada';
	if (value === 'vencida') return 'Vencida';
	return 'Pendiente';
}

function formatCustomerStatus(state: string, creditState: string, balance: number, daysOverdue: number): Customer['status'] {
	if (state !== 'activo' || creditState !== 'activo' || daysOverdue > 0) return 'En mora';
	if (balance > 0) return 'Por vencer';
	return 'Al día';
}