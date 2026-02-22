<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Auth;
use App\Models\Company;
use App\Models\LivreurProfile;
use App\Models\User;

class CompanyController extends Controller
{
    /**
     * GET /api/companies/me
     * Get current user's company
     */
    public function me(Request $request): void
    {
        $this->requireRole('company_manager', 'admin');
        
        $companyModel = new Company();
        $company = $companyModel->findByOwner($this->userId());

        if (!$company) {
            Response::notFound('Company not found for this user');
        }

        Response::success($company);
    }

    /**
     * POST /api/companies
     * Create a new company
     */
    public function store(Request $request): void
    {
        $this->requireRole('company_manager', 'admin');
        $request->validate(['name', 'phone', 'address']);

        $companyModel = new Company();
        
        // Check if user already has a company
        if ($companyModel->findByOwner($this->userId())) {
            Response::error('User already owns a company', 409);
        }

        $data = [
            'name'              => $request->input('name'),
            'owner_id'          => $this->userId(),
            'email'             => $request->input('email'),
            'phone'             => $request->input('phone'),
            'address'           => $request->input('address'),
            'latitude'          => $request->input('latitude'),
            'longitude'         => $request->input('longitude'),
            'registre_commerce' => $request->input('registre_commerce'),
            'status'            => 'pending'
        ];

        // Handle logo upload if present (simplified)
        // if ($request->hasFile('logo')) { ... }

        $id = $companyModel->create($data);
        Response::success($companyModel->find($id), 'Company created successfully', 201);
    }

    /**
     * PUT /api/companies/{id}
     * Update company details
     */
    public function update(Request $request): void
    {
        $this->requireRole('company_manager', 'admin');
        
        $companyModel = new Company();
        $company = $companyModel->find((int) $request->param('id'));

        if (!$company) {
            Response::notFound('Company not found');
        }

        // Check ownership
        if (!Auth::isAdmin() && $company['owner_id'] !== $this->userId()) {
            Response::forbidden();
        }

        $data = [];
        $fields = ['name', 'email', 'phone', 'address', 'latitude', 'longitude', 'registre_commerce'];
        
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        $companyModel->update($company['id'], $data);
        Response::success($companyModel->find($company['id']), 'Company updated');
    }

    /**
     * GET /api/companies/{id}/livreurs
     * List company livreurs
     */
    public function livreurs(Request $request): void
    {
        $this->requireRole('company_manager', 'admin');
        
        $companyModel = new Company();
        $company = $companyModel->find((int) $request->param('id'));

        if (!$company) {
            Response::notFound('Company not found');
        }

        if (!Auth::isAdmin() && $company['owner_id'] !== $this->userId()) {
            Response::forbidden();
        }

        $livreurs = $companyModel->getLivreurs($company['id']);
        Response::success($livreurs);
    }

    /**
     * POST /api/companies/{id}/livreurs
     * Add a livreur to company (by phone number)
     */
    public function addLivreur(Request $request): void
    {
        $this->requireRole('company_manager', 'admin');
        $request->validate(['phone']);
        
        $companyModel = new Company();
        $company = $companyModel->find((int) $request->param('id'));

        if (!$company) {
            Response::notFound('Company not found');
        }

        if (!Auth::isAdmin() && $company['owner_id'] !== $this->userId()) {
            Response::forbidden();
        }

        // Find user by phone
        $userModel = new User();
        // Assuming country_id is needed, or search globally. For now, simple search logic would be needed in User model.
        // This part assumes a method exists or we use raw query
        $phone = $request->input('phone');
        
        // Simplified search for user
        $stmt = $userModel->getDb()->prepare("SELECT id, role FROM users WHERE phone LIKE :phone LIMIT 1");
        $stmt->execute(['phone' => "%$phone"]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::notFound('User not found with this phone');
        }

        if ($user['role'] !== 'livreur') {
            Response::error('User is not a livreur', 400);
        }

        $companyModel->addLivreur($company['id'], $user['id']);
        Response::success([], 'Livreur added to company');
    }

    /**
     * DELETE /api/companies/{id}/livreurs/{livreur_id}
     * Remove livreur from company
     */
    public function removeLivreur(Request $request): void
    {
        $this->requireRole('company_manager', 'admin');
        
        $companyModel = new Company();
        $company = $companyModel->find((int) $request->param('id'));
        $livreurId = (int) $request->param('livreur_id');

        if (!$company) {
            Response::notFound('Company not found');
        }

        if (!Auth::isAdmin() && $company['owner_id'] !== $this->userId()) {
            Response::forbidden();
        }

        $companyModel->removeLivreur($company['id'], $livreurId);
        Response::success([], 'Livreur removed from company');
    }
}
