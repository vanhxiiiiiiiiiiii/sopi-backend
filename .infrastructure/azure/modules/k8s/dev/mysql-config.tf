resource "kubernetes_config_map" "mysql_config" {
  metadata {
    name = "mysql-config"

    labels = {
      app = "mysql"

      component = "database"
    }
  }

  data = {
    MYSQL_DATABASE = "ww_db"

    MYSQL_USER = "ww_username"
  }
}
