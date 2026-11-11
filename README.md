# EDL Hardware Maintenance System (HMS)

## Project Overview

The EDL Hardware Maintenance System (HMS) is a web-based application developed to support hardware asset management and maintenance operations across 20 areas in the Central Province of Sri Lanka.

The system helps manage hardware inventory, report technical issues, schedule maintenance activities, handle purchase requests, and monitor maintenance operations through a centralized platform.

## Technologies Used

- **Frontend:** HTML, CSS, JavaScript
- **Backend:** PHP
- **Database:** Microsoft SQL Server
- **Database Management Tool:** SQL Server Management Studio (SSMS)
- **Development Environment:** Visual Studio Code
- **Web Server:** Microsoft Internet Information Services (IIS)

## Main Modules

- **Inventory Management:** Manage hardware items, stock quantities, and low-stock alerts.
- **Hardware Issue Management:** Report hardware and software issues, upload images, and track issue progress.
- **Maintenance Management:** Schedule maintenance activities and assign technicians.
- **Parts Request Management:** Submit, review, and approve spare parts requests.
- **Purchase Request Management:** Create and manage purchase requests and approval workflows.
- **Notifications:** Notify users about issue updates, assignments, requests, and approvals.
- **Reports:** Generate inventory, maintenance, issue, and purchase-related reports.

## User Roles

- **Admin:** Manages users, inventory, issues, requests, and system operations.
- **User:** Reports hardware and software issues and tracks their progress.
- **Technician:** Handles assigned issues and updates maintenance activities.
- **Storekeeper:** Manages hardware inventory and spare parts.
- **Unit Head:** Reviews and approves purchase requests.

## Security Features

- Role-Based Access Control (RBAC).
- Password hashing and verification.
- Session-based authentication.
- User permission management.
- Input validation and secure database operations.

## Installation and Setup

1. Install PHP and configure Microsoft IIS.
2. Install Microsoft SQL Server.
3. Install SQL Server Management Studio (SSMS).
4. Clone or download this repository.
5. Configure the database connection settings in the application.
6. Create the required database using the provided SQL scripts, if available.
7. Configure IIS to host the HMS application.
8. Open the application using the configured local or intranet URL.

## Project Purpose

This project was developed as part of an Information Technology internship at Electricity Distribution Lanka (Pvt) Ltd (EDL), focusing on improving hardware inventory management and maintenance operations.

## Author

**Maneesha Karunarathne**

BSc (Hons) in Information Technology  
SLIIT
