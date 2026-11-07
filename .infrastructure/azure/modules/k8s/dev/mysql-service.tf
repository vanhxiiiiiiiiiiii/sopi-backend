resource "kubernetes_service" "mysql" {
  metadata {
    name = "mysql"

    labels = {
      app = "mysql"

      component = "database"
    }
  }

  spec {
    port {
      port = 3306
    }

    selector = {
      app = "mysql"

      component = "database"
    }

    cluster_ip = "None"
  }
}
