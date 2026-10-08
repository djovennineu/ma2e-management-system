CREATE TABLE IF NOT EXISTS comptes_techniques (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    libelle VARCHAR(150) NOT NULL,
    solde DECIMAL(15,2) NOT NULL DEFAULT 0,
    statut ENUM('actif','inactif') NOT NULL DEFAULT 'actif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

ALTER TABLE mouvements
    ADD COLUMN compte_technique_id INT UNSIGNED NULL AFTER compte_id;

ALTER TABLE mouvements
    ADD CONSTRAINT fk_mouvements_compte_technique
    FOREIGN KEY (compte_technique_id) REFERENCES comptes_techniques(id);

INSERT INTO comptes_techniques (code, libelle, solde, statut)
SELECT 'TECH-IMMO-MA2E', 'Compte technique immobilier MA2E', 0, 'actif'
WHERE NOT EXISTS (
    SELECT 1 FROM comptes_techniques WHERE code = 'TECH-IMMO-MA2E'
);
