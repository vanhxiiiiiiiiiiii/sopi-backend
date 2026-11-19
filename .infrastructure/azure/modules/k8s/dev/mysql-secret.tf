resource "kubernetes_secret" "mysql_secret" {
  metadata {
    name = "mysql-secret"

    labels = {
      app = "mysql"

      component = "database"
    }
  }

  data = {
    MYSQL_PASSWORD = var.mysql_password

    MYSQL_ROOT_PASSWORD = var.mysql_password
  }
}
