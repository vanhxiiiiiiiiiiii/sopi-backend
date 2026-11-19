resource "kubernetes_stateful_set" "mysql" {
  metadata {
    name = "mysql"

    labels = {
      app = "mysql"

      component = "database"
    }
  }

  spec {
    selector {
      match_labels = {
        app = "mysql"

        component = "database"
      }
    }

    template {
      metadata {
        labels = {
          app = "mysql"

          component = "database"
        }
      }

      spec {
        container {
          name  = "mysql"
          image = "mysql:8.4"

          port {
            container_port = 3306
          }

          env_from {
            config_map_ref {
              name = "mysql-config"
            }
          }

          env_from {
            secret_ref {
              name = "mysql-secret"
            }
          }

          resources {
            limits = {
              cpu = "200m"

              memory = "256Mi"
            }
          }

          volume_mount {
            name       = "mysql-data"
            mount_path = "/var/lib/mysql"
          }

          liveness_probe {
            exec {
              command = ["/bin/sh", "-c", "mysqladmin ping -h 127.0.0.1 -u root -p\"$MYSQL_ROOT_PASSWORD\""]
            }

            initial_delay_seconds = 15
            period_seconds        = 5
          }
        }
      }
    }

    volume_claim_template {
      metadata {
        name = "mysql-data"

        labels = {
          app = "mysql"

          component = "database"
        }
      }

      spec {
        access_modes = ["ReadWriteOnce"]

        resources {
          requests = {
            storage = "500Mi"
          }
        }
      }
    }

    service_name = "mysql"
  }
}
